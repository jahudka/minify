<?php



namespace Minify;



class Source {

    const EXCLUDE_TOKEN = '/** minified */',
        EXCLUDE_TOKEN_LENGTH = 15;

    /** @var array */
    private $directives = [];

    /** @var string */
    private $path;

    /** @var string */
    private $indent;

    /** @var string */
    private $vendorDir = null;

    /** @var string */
    private $bowerDir = null;

    /** @var string */
    private $data;

    /** @var array */
    private $dependencies = [];

    /** @var array  */
    private $lastMod = [];

    /** @var array */
    private $filters = [];

    /** @var array */
    private $blocks = [];

    /** @var bool */
    private $parsed = false;


    /**
     * @param string $path
     * @param string $indent
     */
    public function __construct($path, $indent = null) {
        if (!file_exists($path)) {
            throw new \LogicException("Path $path not found");

        }

        $this->path = realpath($path);
        $this->indent = $indent;
        $this->addDependency($this->path);

    }

    /**
     * @param string $dir
     * @return $this
     */
    public function setVendorDir($dir) {
        $this->vendorDir = $dir;
        return $this;

    }

    /**
     * @param string $dir
     * @return $this
     */
    public function setBowerDir($dir) {
        $this->bowerDir = $dir;
        return $this;

    }

    /**
     * @return string
     */
    public function getPath() {
        return $this->path;

    }

    /**
     * @param string $pattern
     * @param callable $callback
     * @return $this
     */
    public function addFilter($pattern, callable $callback) {
        $this->filters[] = ['pattern' => $pattern, 'callback' => $callback];
        return $this;

    }

    /**
     * @param string $name
     * @param callable $handler
     * @return $this
     */
    public function addDirective($name, callable $handler) {
        $this->directives[$name] = $handler;
        return $this;

    }

    /**
     * Links an external depenedency
     * @param $path
     * @return $this
     */
    public function addDependency($path) {
        $this->dependencies[] = $path;
        $this->lastMod[] = @filemtime($path) ?: 0;
        return $this;

    }

    /**
     * Parses source and returns a list of dependencies
     * @return array
     */
    public function getDependencies() {
        $this->parse();
        return $this->dependencies;

    }

    /**
     * Parses source and returns parsed contents
     * @return string
     */
    public function getContents() {
        $this->parse();
        return $this->data;

    }

    /**
     * Parses source and returns highest last modified timestamp
     * from all the dependencies
     * @return int
     */
    public function getLastModified() {
        $this->parse();
        return max($this->lastMod);

    }

    protected function parse() {
        if ($this->parsed) {
            return;

        }

        $this->data = file_get_contents($this->path);

        if (!empty($this->filters) && !empty($this->data) && strncmp($this->data, self::EXCLUDE_TOKEN, self::EXCLUDE_TOKEN_LENGTH) !== 0) {
            if ($this->indent) {
                $this->data = preg_replace('/^(?!$)/m', $this->indent, $this->data);
                $this->data = substr($this->data, strlen($this->indent));

            }

            foreach ($this->filters as $filter) {
                if (preg_match("\x01({$filter['pattern']})\x01u", $this->path)) {
                    $this->data = call_user_func($filter['callback'], $this->data, $this->path);

                }
            }
        }

        $dir = dirname($this->path);
        $ext = pathinfo($this->path, PATHINFO_EXTENSION);
        $parts = preg_split('#(/\*\![ \t]*@(?:\S+)(?:[ \t]+(?:.+?))?[ \t]*\*/)#', $this->data, null, PREG_SPLIT_DELIM_CAPTURE);
        $blocks = [];
        $currentBlock = null;
        $extends = null;

        for ($i = 0, $n = count($parts); $i < $n; $i++) {
            if (preg_match('#/\*\![ \t]*@(\S+)(?:[ \t]+(.+?))?[ \t]*\*/\z#A', $parts[$i], $m)) {
                if ($i > 0) {
                    preg_match('/([^\n]*)\z/', $parts[$i - 1], $p);
                    $indent = preg_replace('/[^ \t]/', ' ', $p[1]);

                } else {
                    $indent = '';

                }

                $directive = $m[1];
                $params = isSet($m[2]) ? $this->parseParams($m[2]) : [];

                if (array_key_exists($directive, $this->directives)) {
                    $parts[$i] = call_user_func($this->directives[$directive], $params, $dir, $ext, $indent);

                } else if ($directive === 'include') {
                    $parts[$i] = $this->handleInclude($params, $dir, $ext, $indent);

                } else if ($directive === 'package') {
                    $parts[$i] = $this->handlePackage($params, $ext, $indent);

                } else if ($directive === 'extends') {
                    if ($extends) {
                        throw new \LogicException("Multiple extends directives in the same file aren't allowed");

                    } else if (empty($params)) {
                        throw new \LogicException("Invalid @extends directive, either package type and name or file name must be specified");

                    } else {
                        $extends = $params;
                        $parts[$i] = '';

                    }
                } else if ($directive === 'block') {
                    if (@$params['end']) {
                        if (!$currentBlock || $currentBlock !== @$params['name']) {
                            throw new \LogicException("End of a block that isn't open");

                        } else {
                            $currentBlock = null;
                            $parts[$i] = '';

                        }
                    } else {
                        if (empty($params['name'])) {
                            throw new \LogicException("Block name not specified");

                        } else {
                            $currentBlock = $params['name'];
                            $blocks[$currentBlock] = '';
                            $parts[$i] = '';

                        }
                    }
                } else {
                    throw new \LogicException("Invalid directive: {$directive}");

                }
            }

            if ($currentBlock) {
                $blocks[$currentBlock] .= $parts[$i];

            }
        }

        if ($extends) {
            $this->data = $this->handleExtends($extends, $blocks, $dir, $ext);

        } else {
            $this->data = implode('', $parts);

        }

        $this->parsed = true;

    }

    protected function handleInclude($params, $dir, $ext, $indent) {
        if (isSet($params['block'])) {
            return isSet($this->blocks[$params['block']]) ? $this->blocks[$params['block']] : '';

        }

        $this->validateIncludeParams($params);

        if (isSet($params['file'])) {
            return $this->includeFile($dir . '/' . $params['file'], $indent);

        } else /*if ($s[1] === 'dir')*/ {
            $types = isSet($params['types']) ? preg_split('/\s*,\s*/', $params['types']) : [$ext];
            $recursive = isSet($params['recursive']) && $params['recursive'];

            return $this->includeDir($dir . '/' . $params['dir'], $types, $recursive, $indent);

        }
    }

    protected function handlePackage($params, $ext, $indent) {
        $this->validatePackageParams($params);

        $packageRoot = $this->getPackageRoot($params['type']);
        $path = $packageRoot . '/' . $params['name'];
        $types = $ext === 'js' ? ['js'] : ['less', 'css'];

        if (isSet($params['file'])) {
            return $this->includeFile($path . '/' . $params['file'], $indent);

        } else if (isSet($params['dir'])) {
            $recursive = isSet($params['recursive']) && $params['recursive'];
            return $this->includeDir($path . '/' . $params['dir'], $types, $recursive, $indent);

        } else {
            foreach ($types as $ext) {
                if (is_file($path . '/loader.' . $ext)) {
                    return $this->includeFile($path . '/loader.' . $ext, $indent);

                }
            }

            return $this->includeDir($path, $types, true, $indent);

        }
    }

    protected function handleExtends($params, array $blocks, $dir, $ext) {
        if (@$params['package']) {
            $this->validatePackageParams($params);
            $basePath = $this->getPackageRoot($params['type']) . '/' . $params['name'];

        } else {
            $basePath = $dir;

        }

        if (isSet($params['file'])) {
            $path = $basePath . '/' . $params['file'];

        } else {
            if (!@$params['package']) {
                throw new \LogicException("Invalid @extends directive, either specify a package or a file to extend");

            }

            $types = $ext === 'js' ? ['js'] : ['less', 'css'];

            foreach ($types as $ext) {
                if (is_file($basePath . '/loader.' . $ext)) {
                    $path = $basePath . '/loader.' . $ext;
                    break;

                }
            }
        }

        if (!isSet($path) || !is_file($path)) {
            throw new \LogicException("Invalid @extends directive: path not specified or file not found");

        }

        $src = new static($path);
        $src->vendorDir = $this->vendorDir;
        $src->bowerDir = $this->bowerDir;
        $src->filters = $this->filters;
        $src->directives = $this->directives;
        $src->blocks = $blocks;

        foreach ($src->getDependencies() as $dep) {
            $this->addDependency($dep);

        }

        return $src->getContents();

    }

    public function includeFile($path, $indent) {
        $src = new static($path, $this->indent . $indent);
        $src->vendorDir = $this->vendorDir;
        $src->bowerDir = $this->bowerDir;
        $src->filters = $this->filters;
        $src->directives = $this->directives;

        foreach ($src->getDependencies() as $dep) {
            $this->addDependency($dep);

        }

        return $src->getContents();

    }

    public function includeDir($path, $types, $recursive, $indent) {
        if (!file_exists($path) || !is_dir($path)) {
            throw new \LogicException("$path does not exist or is not a directory");

        }

        $files = Finder::find($path, $types, $recursive);

        $contents = '';

        foreach ($files as $file) {
            /** @var $file \SplFileInfo */
            if ($file->isDir()) {
                continue;

            }

            $contents .= $this->includeFile($file->getRealPath(), $indent) . "\n";

        }

        return $contents;

    }

    protected function getPackageRoot($type) {
        if ($type === 'composer') {
            if (!$this->vendorDir) {
                throw new \LogicException('Composer support is disabled');

            }

            return $this->vendorDir;

        } else if ($type === 'bower') {
            if (!$this->bowerDir) {
                throw new \LogicException('Composer support is disabled');

            }

            return $this->bowerDir;

        } else {
            throw new \LogicException('Unknown package type: ' . $type);

        }
    }

    protected function validatePackageParams(array $params) {
        if (!isSet($params['type'])) {
            throw new \LogicException('Package type not specified');

        } else if (!isSet($params['name'])) {
            throw new \LogicException('Package name not specified');

        }
    }

    protected function validateIncludeParams(array $params) {
        if (!isSet($params['file']) && !isSet($params['dir'])) {
            throw new \LogicException('@include directive does not have a "file" or "dir" attribute');

        }
    }

    protected function parseParams($params) {
        $parsed = [];

        if (preg_match_all('/([a-z0-9.:-]+)(?:=("(?:\\.|[^"\\\n])*"|\S+))?/i', $params, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $param) {
                if (isSet($param[2])) {
                    $parsed[$param[1]] = trim($param[2], '"');

                } else {
                    $parsed[$param[1]] = true;

                }
            }
        }

        return $parsed;

    }

    public function __toString() {
        return $this->getContents();

    }

}
