<?php
namespace PHPCD;

use Psr\Log\LoggerInterface as Logger;
use Lvht\MsgpackRpc\Server as RpcServer;
use Lvht\MsgpackRpc\Handler as RpcHandler;

class PHPID implements RpcHandler
{
    /**
     * @var RpcServer
     */
    private $server;

    /**
     * @var Logger
     */
    private $logger;

    private $root;

    public function __construct($root, Logger $logger)
    {
        $this->root = $root;
        $this->logger = $logger;
    }

    public function setServer(RpcServer $server)
    {
        $this->server = $server;
    }

    /**
     * update index for one class
     *
     * @param string $className fqdn
     */
    public function update($className)
    {
        list($parent, $interfaces) = $this->getClassInfo($className);

        if ($parent) {
            $this->updateParentIndex($parent, $className);
        }
        foreach ($interfaces as $interface) {
            $this->updateInterfaceIndex($interface, $className);
        }
    }

    /**
     * Fetch an interface's implemation list,
     * or an abstract class's child class.
     *
     * @param string $name name of interface or abstract class
     * @param bool $isAbstractClass
     *
     * @return [
     *   'full class name 1',
     *   'full class name 2',
     * ]
     */
    public function ls($name, $isAbstractClass = false)
    {
        $basePath = $isAbstractClass ? $this->getIntefacesDir()
            : $this->getExtendsDir();
        $path = $basePath . '/' . $this->getIndexFileName($name);
        if (!is_file($path)) {
            return [];
        }

        $list = json_decode(file_get_contents($path));
        if (!is_array($list)) {
            return [];
        }

        sort($list);

        return $list;
    }

    /**
     * Fetch and save class's interface and parent info
     * according the autoload_classmap.php file
     *
     * @param bool $is_force overwrite the exists index
     */
    public function index()
    {
        $this->initIndexDir();

        exec('composer dump-autoload -o -d ' . $this->root . ' 2>&1 >/dev/null');
        $this->class_map = require $this->root
            . '/vendor/composer/autoload_classmap.php';

        $pipePath = sys_get_temp_dir() . '/' . uniqid();
        posix_mkfifo($pipePath, 0600);

        $this->vimOpenProgressBar(count($this->class_map));

        while ($this->class_map) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                die('could not fork');
            } elseif ($pid > 0) {
                // 父进程
                $pipe = fopen($pipePath, 'r');
                $data = fgets($pipe);
                $this->class_map = json_decode(trim($data), true);
                pcntl_waitpid($pid, $status);
            } else {
                // 子进程
                $pipe = fopen($pipePath, 'w');
                register_shutdown_function(function () use ($pipe) {
                    $data = json_encode($this->class_map, true);
                    fwrite($pipe, "$data\n");
                    fclose($pipe);
                });
                $this->_index();
                fwrite($pipe, "[]\n");
                fclose($pipe);
                exit;
            }
        }
        fclose($pipe);
        unlink($pipePath);
        $this->vimCloseProgressBar();
    }

    private function getIndexDir()
    {
        return $this->root . '/.phpcd';
    }

    private function getIntefacesDir()
    {
        return $this->getIndexDir() . '/interfaces';
    }

    private function getExtendsDir()
    {
        return $this->getIndexDir() . '/extends';
    }

    private function initIndexDir()
    {
        $extends_dir = $this->getExtendsDir();
        if (!is_dir($extends_dir)) {
            mkdir($extends_dir, 0700, true);
        }

        $interfaces_dir = $this->getIntefacesDir();
        if (!is_dir($interfaces_dir)) {
            mkdir($interfaces_dir, 0700, true);
        }
    }

    private function _index()
    {
        foreach ($this->class_map as $className => $file_path) {
            unset($this->class_map[$className]);
            $this->vimUpdateProgressBar();
            require $file_path;
            $this->update($className);
        }
    }

    private function updateParentIndex($parent, $child)
    {
        $indexFile = $this->getExtendsDir() . '/' . $this->getIndexFileName($parent);
        $this->saveChild($indexFile, $child);
    }

    private function updateInterfaceIndex($interface, $implementation)
    {
        $indexFile = $this->getIntefacesDir() . '/' . $this->getIndexFileName($interface);
        $this->saveChild($indexFile, $implementation);
    }

    private function saveChild($indexFile, $child)
    {
        $indexDirectory = dirname($indexFile);

        if (!is_dir($indexDirectory)) {
            mkdir($indexDirectory, 0755, true);
        }

        if (is_file($indexFile)) {
            $childs = json_decode(file_get_contents($indexFile));
        } else {
            $childs = [];
        }

        $childs[] = $child;
        $childs = array_unique($childs);
        file_put_contents($indexFile, json_encode($childs));
    }

    private function getIndexFileName($name)
    {
        return str_replace("\\", '_', $name);
    }

    private function getClassInfo($name) {
        try {
            $reflection = new \ReflectionClass($name);

            $parent = $reflection->getParentClass();
            if ($parent) {
                $parent = $parent->getName();
            }

            $interfaces = array_keys($reflection->getInterfaces());

            return [$parent, $interfaces];
        } catch (\ReflectionException $e) {
            return [null, []];
        }
    }

    private function vimOpenProgressBar($max)
    {
        $cmd = 'let g:pb = vim#widgets#progressbar#NewSimpleProgressBar("Indexing:", ' . $max . ')';
        $this->server->call('vim_command', [$cmd]);
    }

    private function vimUpdateProgressBar()
    {
        $this->server->call('vim_command', ['call g:pb.incr()']);
    }

    private function vimCloseProgressBar()
    {
        $this->server->call('vim_command', ['call g:pb.restore()']);
    }
}
