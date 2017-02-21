<?php
namespace PHPCD;

use Psr\Log\LoggerInterface as Logger;
use Lvht\MsgpackRpc\Server as RpcServer;
use Lvht\MsgpackRpc\Handler as RpcHandler;

class PHPCD implements RpcHandler
{
    const MATCH_SUBSEQUENCE = 'match_subsequence';
    const MATCH_HEAD        = 'match_head';

    private $matchType;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var RpcServer
     */
    private $server;

    private $root;

    public function __construct($root, Logger $logger, $matchType = self::MATCH_HEAD)
    {
        $this->logger    = $logger;
        $this->root      = $root;
        $this->matchType = $matchType;
    }

    public function setServer(RpcServer $server)
    {
        $this->server = $server;
    }

    /**
     * Set type of matching
     *
     * @param string $matchType
     */
    public function setMatchType($matchType)
    {
        if ($matchType !== self::MATCH_SUBSEQUENCE && $matchType !== self::MATCH_HEAD) {
            throw new \InvalidArgumentException('Wrong match type');
        }

        $this->matchType = $matchType;
    }

    /**
     *  @param array Map between modifier numbers and displayed symbols
     */
    private $modifierSymbols = [
        \ReflectionMethod::IS_FINAL      => '!',
        \ReflectionMethod::IS_PRIVATE    => '-',
        \ReflectionMethod::IS_PROTECTED  => '#',
        \ReflectionMethod::IS_PUBLIC     => '+',
        \ReflectionMethod::IS_STATIC     => '@'
    ];

    /**
     * @param string $mode
     * @return bool|null
     */
    private function translateStaticMode($mode)
    {
        $map = [
            'both'           => null,
            'only_nonstatic' => false,
            'only_static'    => true
        ];

        return isset($map[$mode]) ? $map[$mode] : null;
    }

    /**
     * Fetch the completion list.
     *
     * If both $className and $pattern are setted, it will list the class's
     * methods, constants, and properties, filted by pattern.
     *
     * If only $pattern is setted, it will list all the defined function
     * (including the PHP's builtin function', filted by pattern.
     *
     * @var string $className
     * @var string $pattern
     * @var string $staticMode see translateStaticMode method
     * @var bool $publicOnly
     */
    public function info($className, $pattern, $staticMode = 'both', $publicOnly = true)
    {
        if ($className) {
            $staticMode = $this->translateStaticMode($staticMode);
            return $this->classInfo($className, $pattern, $staticMode, $publicOnly);
        }

        if ($pattern) {
            return $this->functionOrConstantInfo($pattern);
        }

        return [];
    }

    /**
     * Fetch function or class method's source file path
     * and their defination line number.
     *
     * @param string $className class name
     * @param string $methodName method or function name
     *
     * @return [path, line]
     */
    public function location($className, $methodName = null)
    {
        try {
            if ($className) {
                $reflection = new \ReflectionClass($className);

                if ($reflection->hasMethod($methodName)) {
                    $reflection = $reflection->getMethod($methodName);
                } elseif ($reflection->hasConstant($methodName)) {
                    // 常量则返回 [ path, 'const constName' ]
                    return [$this->getConstPath($methodName, $reflection), 'const ' . $methodName];
                } elseif ($reflection->hasProperty($methodName)) {
                    list($file, $line) = $this->getPropertyDefLine($reflection, $methodName);
                    return [$file, $line];
                }
            } else {
                $reflection = new \ReflectionFunction($methodName);
            }

            return [$reflection->getFileName(), $reflection->getStartLine()];
        } catch (\ReflectionException $e) {
            return ['', null];
        }
    }

    private function getPropertyDefLine($classReflection, $property)
    {
        $find = function($classReflection, $property) {
            $class = new \SplFileObject($classReflection->getFileName());
            $class->seek($classReflection->getStartLine());

            $pattern = '/(private|protected|public|var)\s\$' . $property . '/x';
            foreach ($class as $line => $content) {
                if (preg_match($pattern, $content)) {
                    return $line + 1;
                }
            }
            return false;
        };

        $start = $classReflection->getStartLine();
        $file = $classReflection->getFileName();

        do {
            if (false !== ($line = $find($classReflection, $property))) {
                return [$classReflection->getFileName(), $line];
            }
            $classReflection = $classReflection->getParentClass();
        } while ($classReflection && ($line === false));

        return [$file, $start];
    }

    private function getConstPath($constName, \ReflectionClass $reflection)
    {
        $origin = $path = $reflection->getFileName();
        $originReflection = $reflection;

        while ($reflection = $reflection->getParentClass()) {
            if ($reflection->hasConstant($constName)) {
                $path = $reflection->getFileName();
            } else {
                break;
            }
        }

        if ($origin === $path) {
            $interfaces = $originReflection->getInterfaces();
            foreach ($interfaces as $interface) {
                if ($interface->hasConstant($constName)) {
                    $path = $interface->getFileName();
                    break;
                }
            }
        }

        return $path;
    }

    /**
     * Fetch function, class method or class attribute's docblock
     *
     * @param string $className for function set this args to empty
     * @param string $name
     */
    private function doc($className, $name, $isMethod = true)
    {
        try {
            if (!$className) {
                return $this->docFunction($name);
            }

            return $this->docClass($className, $name, $isMethod);
        } catch (\ReflectionException $e) {
            $this->logger->debug($e->getMessage());
            return [null, null];
        }
    }

    private function docFunction($name)
    {
        $reflection = new \ReflectionFunction($name);
        $doc = $reflection->getDocComment();
        $path = $reflection->getFileName();

        return [$path, $this->clearDoc($doc)];
    }

    private function docClass($className, $name, $isMethod)
    {
        $reflectionClass = new \ReflectionClass($className);

        if ($isMethod) {
            $reflection = $reflectionClass->getMethod($name);
        } else {
            if ($reflectionClass->hasProperty($name)) {
                $reflection = $reflectionClass->getProperty($name);
            } else {
                $classDoc = $reflectionClass->getDocComment();

                $hasPseudoProperty = preg_match('/@property(|-read|-write)\s+(?<type>\S+)\s+\$?'.$name.'/mi', $classDoc, $matches);
                if ($hasPseudoProperty) {
                    return [$reflectionClass->getFileName(), '@var '.$matches['type']];
                }
            }
        }

        $doc = $reflection->getDocComment();

        if ($isMethod && preg_match('/@inheritDoc/', $doc)) {
            $reflection = $this->getReflectionFromInheritDoc($reflectionClass, $name);
            $doc = $reflection->getDocComment();
        }

        if (preg_match('/@(return|var)\s+static/i', $doc)) {
            $path = $reflectionClass->getFileName();
        } else {
            $path = $reflection->getDeclaringClass()->getFileName();
        }

        return [$path, $this->clearDoc($doc)];
    }

    /**
     * Get the origin method reflection the inherited docComment belongs to.
     *
     * @param $reflectionClass \ReflectionClass
     * @param $name string
     *
     * @return \ReflectionClass
     */
    private function getReflectionFromInheritDoc($reflectionClass, $methodName)
    {
        $interfaces = $reflectionClass->getInterfaces();

        foreach ($interfaces as $interface) {
            if ($interface->hasMethod($methodName)) {
                return $interface->getMethod($methodName);
            }
        }

        $reflectionMethod = $reflectionClass->getMethod($methodName);
        $parentClass = $reflectionClass->getParentClass();

        if ($parentClass) {
            $reflectionMethod = $parentClass->getMethod($methodName);
            $doc = $reflectionMethod->getDocComment();
            if (preg_match('/@inheritDoc/', $doc)) {
                $reflectionMethod = $this->getInheritDoc($parentClass, $methodName);
            }
        }

        return $reflectionMethod;
    }

    /**
     * Fetch the php script's namespace and imports(by use) list.
     *
     * @param string $path the php scrpit path
     *
     * @return [
     *   'namespace' => 'ns',
     *   'imports' => [
     *     'alias1' => 'fqdn1',
     *   ]
     * ]
     */
    public function nsuse($path)
    {
        $usePattern =
            '/^use\s+((?<type>(constant|function)) )?(?<left>[\\\\\w]+\\\\)?({)?(?<right>[\\\\,\w\s]+)(})?\s*;$/';
        $aliasPattern = '/(?<suffix>[\\\\\w]+)(\s+as\s+(?<alias>\w+))?/';
        $classPattern = '/^\s*\b((((final|abstract)\s+)?class)|interface|trait)\s+(?<class>\S+)/i';

        $s = [
            'namespace' => '',
            'imports' => [
                '@' => '',
                // empty array will be enoded to "[]" by json
                // so when there is no import we need convert
                // the empty array into stdobj
                // which will be encoded to "{}" by json
                // however the msgpack used by neovim does not allowed dictionary
                // with empty key. so we have no choice but fill import some
                // value to ensure none empty.
            ],
            'class' => '',
        ];

        if (!file_exists($path)) {
            return $s;
        }

        $file = new \SplFileObject($path);
        foreach ($file as $line) {
            if (preg_match($classPattern, $line, $matches)) {
                $s['class'] = $matches['class'];
                break;
            }
            $line = trim($line);
            if (!$line) {
                continue;
            }
            if (preg_match('/(<\?php)?\s*namespace\s+(.*);$/', $line, $matches)) {
                $s['namespace'] = $matches[2];
            } elseif (strtolower(substr($line, 0, 3) == 'use')) {
                if (preg_match($usePattern, $line, $useMatches) && !empty($useMatches)) {
                    $expansions = array_map('self::trim', explode(',', $useMatches['right']));

                    foreach ($expansions as $expansion) {
                        if (preg_match($aliasPattern, $expansion, $expansionMatches) && !empty($expansionMatches)) {
                            $suffix = $expansionMatches['suffix'];

                            if (empty($expansionMatches['alias'])) {
                                $suffix_parts = explode('\\', $suffix);
                                $alias = array_pop($suffix_parts);
                            } else {
                                $alias = $expansionMatches['alias'];
                            }
                        }

                        /** empty type means import of some class **/
                        if (empty($useMatches['type'])) {
                            $s['imports'][$alias] = $useMatches['left'] . $suffix;
                        }
                        // @todo case when $useMatches['type'] is 'constant' or 'function'
                    }
                }
            }
        }

        return $s;
    }

    private static function trim($str)
    {
        return trim($str, "\t\n\r\0\x0B\\ ");
    }

    /**
     * Fetch the function or class method return value's type
     *
     * For PHP7 or newer version, it tries to use the return type gramar
     * to fetch the real return type.
     *
     * For PHP5, it use the docblock's return or var annotation to fetch
     * the type.
     *
     * @return [type1, type2]
     */
    public function functype($className, $name)
    {
        if (version_compare(PHP_VERSION, '7.0.0') >= 0) {
            $type = $this->typeByReturnType($className, $name);
            if ($type) {
                return [$type];
            }
        }

        list($path, $doc) = $this->doc($className, $name);
        return $this->typeByDoc($path, $doc, $className);
    }

    /**
     * Fetch class attribute's type by @var annotation
     *
     * @return [type1, type2, ...]
     */
    public function proptype($className, $name)
    {
        list($path, $doc) = $this->doc($className, $name, false);
        $types = $this->typeByDoc($path, $doc, $className);

        return $types;
    }

    private function typeByReturnType($className, $name)
    {
        try {
            if ($className) {
                $reflection = new \ReflectionClass($className);
                $reflection = $reflection->getMethod($name);
            } else {
                $reflection = new \ReflectionFunction($name);
            }
            $type = (string) $reflection->getReturnType();

            if (strtolower($type) == 'self') {
                $type = $className;
            }

            return $type;
        } catch (\ReflectionException $e) {
            $this->logger->debug((string) $e);
        }
    }

    private function typeByDoc($path, $doc, $className)
    {
        $hasDoc = preg_match('/@(return|var)\s+(\S+)/m', $doc, $matches);
        if ($hasDoc) {
            return $this->fixRelativeType($path, explode('|', $matches[2]));
        }

        return [];
    }

    private function fixRelativeType($path, $names)
    {
        $nsuse = null;

        $types = [];
        foreach ($names as $type) {
            if (isset($this->primitive_types[$type])) {
                continue;
            }

            if (!$nsuse && $type[0] != '\\') {
                $nsuse = $this->nsuse($path);
            }

            if (in_array(strtolower($type) , ['static', '$this', 'self'])) {
                $type = $nsuse['namespace'] . '\\' . $nsuse['class'];
            } elseif ($type[0] != '\\') {
                $parts = explode('\\', $type);
                $alias = array_shift($parts);
                if (isset($nsuse['imports'][$alias])) {
                    $type = $nsuse['imports'][$alias];
                    if ($parts) {
                        $type = $type . '\\' . join('\\', $parts);
                    }
                } else {
                    $type = $nsuse['namespace'] . '\\' . $type;
                }
            }

            if ($type) {
                if ($type[0] != '\\') {
                    $type = '\\' . $type;
                }
                $types[] = $type;
            }
        }

        return self::arrayUnique($types);
    }

    private static function arrayUnique($array)
    {
        $_ = [];
        foreach ($array as $a) {
            $_[$a] = 1;
        }

        return array_keys($_);
    }

    private $primitive_types = [
        'array'    => 1,
        'bool'     => 1,
        'callable' => 1,
        'double'   => 1,
        'float'    => 1,
        'int'      => 1,
        'mixed'    => 1,
        'null'     => 1,
        'object'   => 1,
        'resource' => 1,
        'scalar'   => 1,
        'string'   => 1,
        'void'     => 1,
    ];

    private function classInfo($className, $pattern, $isStatic, $publicOnly)
    {
        try {
            $reflection = new \PHPCD\Reflection\ReflectionClass($className);
            $items = [];

            if (false !== $isStatic) {
                foreach ($reflection->getConstants() as $name => $value) {
                    if (!$pattern || $this->matchPattern($pattern, $name)) {
                        if (is_array($value)) {
                            $value = '[...]';
                        }

                        $items[] = [
                            'word' => $name,
                            'abbr' => sprintf(" +@ %s %s", $name, $value),
                            'kind' => 'd',
                            'icase' => 1,
                        ];
                    }
                }
            }

            $methods = $reflection->getAvailableMethods($isStatic, $publicOnly);

            foreach ($methods as $method) {
                $info = $this->getMethodInfo($method, $pattern);
                if ($info) {
                    $items[] = $info;
                }
            }

            $properties = $reflection->getAvailableProperties($isStatic, $publicOnly);

            foreach ($properties as $property) {
                $info = $this->getPropertyInfo($property, $pattern);
                if ($info) {
                    $items[] = $info;
                }
            }

            $pseudoItems = $this->getPseudoProperties($reflection);

            $items = array_merge($items, $pseudoItems);

            return $items;
        } catch (\ReflectionException $e) {
            $this->logger->debug($e->getMessage());
            return [];
        }
    }

    public function getPseudoProperties(\ReflectionClass $reflection)
    {
        $doc = $reflection->getDocComment();
        $hasDoc = preg_match_all('/@property(|-read|-write)\s+(?<types>\S+)\s+\$?(?<names>[a-zA-Z0-9_$]+)/mi', $doc, $matches);
        if (!$hasDoc) {
            return [];
        }

        $items = [];
        foreach ($matches['names'] as $idx => $name) {
            $items[] = [
                'word' => $name,
                'abbr' => sprintf('%3s %s', '+', $name),
                'info' => $matches['types'][$idx],
                'kind' => 'p',
                'icase' => 1,
            ];
        }

        return $items;
    }

    private function functionOrConstantInfo($pattern)
    {
        $items = [];
        $funcs = get_defined_functions();
        foreach ($funcs['internal'] as $func) {
            $info = $this->getFunctionInfo($func, $pattern);
            if ($info) {
                $items[] = $info;
            }
        }
        foreach ($funcs['user'] as $func) {
            $info = $this->getFunctionInfo($func, $pattern);
            if ($info) {
                $items[] = $info;
            }
        }

        return array_merge($items, $this->getConstantsInfo($pattern));
    }

    private function getConstantsInfo($pattern)
    {
        $items = [];
        foreach (get_defined_constants() as $name => $value) {
            if ($pattern && strpos($name, $pattern) !== 0) {
                continue;
            }

            $items[] = [
                'word' => $name,
                'abbr' => "@ $name = $value",
                'kind' => 'd',
                'icase' => 0,
            ];
        }

        return $items;
    }

    private function getFunctionInfo($name, $pattern = null)
    {
        if ($pattern && strpos($name, $pattern) !== 0) {
            return null;
        }

        $reflection = new \ReflectionFunction($name);
        $params = array_map(function ($param) {
            return $param->getName();
        }, $reflection->getParameters());

        return [
            'word' => $name,
            'abbr' => "$name(" . join(', ', $params) . ')',
            'info' => preg_replace('#/?\*(\*|/)?#','', $reflection->getDocComment()),
            'kind' => 'f',
            'icase' => 1,
        ];
    }

    private function getPropertyInfo($property, $pattern)
    {
        $name = $property->getName();
        if ($pattern && !$this->matchPattern($pattern, $name)) {
            return null;
        }

        $modifier = $this->getModifiers($property);
        if ($property->getModifiers() & \ReflectionMethod::IS_STATIC) {
            $name = '$'.$name;
        }

        return [
            'word' => $name,
            'abbr' => sprintf("%3s %s", $modifier, $name),
            'info' => preg_replace('#/?\*(\*|/)?#', '', $property->getDocComment()),
            'kind' => 'p',
            'icase' => 1,
        ];
    }

    private function getMethodInfo($method, $pattern = null)
    {
        $name = $method->getName();
        if ($pattern && !$this->matchPattern($pattern, $name)) {
            return null;
        }

        $params = array_map(function ($param) {
            return $param->getName();
        }, $method->getParameters());

        $modifier = $this->getModifiers($method);

        return [
            'word' => $name,
            'abbr' => sprintf("%3s %s (%s)", $modifier, $name, join(', ', $params)),
            'info' => $this->clearDoc($method->getDocComment()),
            'kind' => 'f',
            'icase' => 1,
        ];
    }

    /**
     * @return bool
     */
    private function matchPattern($pattern, $fullString)
    {
        if (!$pattern) {
            return true;
        }

        switch ($this->matchType) {
        case self::MATCH_SUBSEQUENCE:
            // @TODO Case sensitivity of matching should be probably configurable
            $modifiers = 'i';
            $regex = sprintf('/%s/%s', implode('.*', array_map('preg_quote', str_split($pattern))), $modifiers);

            return (bool)preg_match($regex, $fullString);
        default:
            return stripos($fullString, $pattern) === 0;
        }
    }

    /**
     *
     * @return array
     */
    private function getModifierSymbols()
    {
        return $this->modifierSymbols;
    }

    private function getModifiers($reflection)
    {
        $signs = '';

        $modifiers = $reflection->getModifiers();
        $symbols = $this->getModifierSymbols();

        foreach ($symbols as $number => $sign) {
            if ($number & $modifiers) {
                $signs .= $sign;
            }
        }

        return $signs;
    }

    private function clearDoc($doc)
    {
        $doc = preg_replace('/[ \t]*\* ?/m','', $doc);
        return preg_replace('#\s*\/|/\s*#','', $doc);
    }

    /**
     * generate psr4 namespace according composer.json and file path
     */
    public function psr4ns($path)
    {
        $dir = dirname($path);

        $composerPath = $this->root . '/composer.json';

        if (!is_readable($composerPath)) {
            return [];
        }

        $composer = json_decode(file_get_contents($composerPath), true);

        if (isset($composer['autoload']['psr-4'])) {
            $list = (array) $composer['autoload']['psr-4'];
        } else {
            $list = [];
        }

        if (isset($composer['autoload-dev']['psr-4'])) {
            $devList = (array) $composer['autoload-dev']['psr-4'];
        } else {
            $devList = [];
        }

        foreach ($devList as $namespace => $paths) {
            if (isset($list[$namespace])) {
                $list[$namespace] = array_merge((array)$list[$namespace], (array) $paths);
            } else {
                $list[$namespace] = (array) $paths;
            }
        }

        $namespaces = [];
        foreach ($list as $namespace => $paths) {
            foreach ((array)$paths as $path) {
                $path = realpath($this->root.'/'.$path);
                if (strpos($dir, $path) === 0) {
                    $subPath = str_replace($path, '', $dir);
                    $subPath = str_replace('/', '\\', $subPath);
                    $subNamespace = trim($subPath, '\\');
                    if ($subNamespace) {
                        $subNamespace = '\\' . $subNamespace;
                    }
                    $namespaces[] = trim($namespace, '\\').$subNamespace;
                }
            }
        }

        return $namespaces;
    }
}
