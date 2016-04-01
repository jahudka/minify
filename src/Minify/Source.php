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
        $parts = preg_split('#(/\*\![ \t]*@(?:\S+)[ \t]+(?:.+?)[ \t]*\*/)#', $this->data, null, PREG_SPLIT_DELIM_CAPTURE);

        for ($i = 0, $n = count($parts); $i < $n; $i++) {
            if (preg_match('#/\*\![ \t]*@(\S+)[ \t]+(.+?)[ \t]*\*/\z#A', $parts[$i], $m)) {
                if ($i > 0) {
                    preg_match('/([^\n]*)\z/', $parts[$i - 1], $p);
                    $indent = preg_replace('/[^ \t]/', ' ', $p[1]);

                } else {
                    $indent = '';

                }

                $directive = $m[1];
                $params = $this->parseParams($m[2]);

                if (array_key_exists($directive, $this->directives)) {
                    $parts[$i] = call_user_func($this->directives[$directive], $params, $dir, $ext, $indent);

                } else if ($directive === 'include') {
                    $parts[$i] = $this->handleInclude($params, $dir, $ext, $indent);

                } else if ($directive === 'package') {
                    $parts[$i] = $this->handlePackage($params, $ext, $indent);

                } else {
                    throw new \LogicException("Invalid directive: {$directive}");

                }
            }
        }

        $this->data = implode('', $parts);
        $this->parsed = true;

    }

    protected function handleInclude($params, $dir, $ext, $indent) {
        if (!isSet($params['file']) && !isSet($params['dir'])) {
            throw new \LogicException('@include directive does not have a "file" or "dir" attribute');

        }

        if (isSet($params['file'])) {
            return $this->includeFile($dir . '/' . $params['file'], $indent);

        } else /*if ($s[1] === 'dir')*/ {
            $types = isSet($params['types']) ? preg_split('/\s*,\s*/', $params['types']) : [$ext];
            $recursive = isSet($params['recursive']) && $params['recursive'];

            return $this->includeDir($dir . '/' . $params['dir'], $types, $recursive, $indent);

        }
    }

    protected function handlePackage($params, $ext, $indent) {
        if (!isSet($params['type'])) {
            throw new \LogicException('Package type not specified');

        } else if (!isSet($params['name'])) {
            throw new \LogicException('Package name not specified');

        } else if ($params['type'] === 'composer') {
            if (!$this->vendorDir) {
                throw new \LogicException('Composer support is disabled');

            }

            $packageRoot = $this->vendorDir;

        } else if ($params['type'] === 'bower') {
            if (!$this->bowerDir) {
                throw new \LogicException('Composer support is disabled');

            }

            $packageRoot = $this->bowerDir;

        } else {
            throw new \LogicException('Unknown package type: ' . $params['type']);

        }

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
