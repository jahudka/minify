<?php



namespace Minify;



class Source {

    const EXCLUDE_TOKEN = '/** minified */',
        EXCLUDE_TOKEN_LENGTH = 15;

    /** @var string */
    private $path;

    /** @var string */
    private $indent;

    /** @var string */
    private $vendorDir = null;

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

                if ($m[1] === 'include') {
                    $parts[$i] = $this->handleInclude($m[2], $dir, $ext, $indent);

                } else if ($m[1] === 'package') {
                    $parts[$i] = $this->handlePackage($m[2], $ext, $indent);

                } else {
                    throw new \LogicException("Invalid directive: {$m[1]}");

                }
            }
        }

        $this->data = implode('', $parts);
        $this->parsed = true;

    }

    protected function handleInclude($params, $dir, $ext, $indent) {
        if (!preg_match('/(?<=^|\s)(file|dir)=("|\')(.+?)\2/', $params, $s)) {
            throw new \LogicException('@include directive does not have a "file" or "dir" attribute');

        }

        if ($s[1] === 'file') {
            return $this->includeFile($dir . '/' . $s[3], $indent);

        } else /*if ($s[1] === 'dir')*/ {
            $types = $this->parseTypes($params, $ext);
            $recursive = $this->parseRecursive($params);

            return $this->includeDir($dir . '/' . $s[3], $types, $recursive, $indent);

        }
    }

    protected function includeFile($path, $indent) {
        $src = new static($path, $this->indent . $indent);
        $src->vendorDir = $this->vendorDir;
        $src->filters = $this->filters;

        foreach ($src->getDependencies() as $dep) {
            $this->addDependency($dep);

        }

        return $src->getContents();

    }

    protected function includeDir($path, $types, $recursive, $indent) {
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

    protected function handlePackage($params, $ext, $indent) {
        if (!$this->vendorDir) {
            throw new \LogicException('Composer support is disabled');

        }

        if (preg_match('/(?<=^|\s)name=("|\')(.+?)\1/', $params, $p)) {
            $path = $this->vendorDir . '/' . $p[2];
            $types = $this->parseTypes($params, $ext);

            if (preg_match('/(?<=^|\s)(file|dir)=("|\')(.+?)\2/', $params, $f)) {
                if ($f[1] === 'file') {
                    return $this->includeFile($path . '/' . $f[3], $indent);

                } else {
                    $recursive = $this->parseRecursive($params);
                    return $this->includeDir($path . '/' . $f[3], $types, $recursive, $indent);

                }
            } else {
                if (is_file($path . '/loader.' . $ext)) {
                    return $this->includeFile($path . '/loader.' . $ext, $indent);

                } else {
                    return $this->includeDir($path, $types, true, $indent);

                }
            }
        } else {
            $path = $this->vendorDir . '/' . trim($params);

            if (is_file($path)) {
                return $this->includeFile($path, $indent);

            } else if (is_dir($path)) {
                return $this->includeDir($path, [$ext], true, $indent);

            }
        }
    }

    protected function parseTypes($params, $ext) {
        if (preg_match('/(?<=^|\s)types?=("|\')(.+?)\1/', $params, $p)) {
            return preg_split('/[,\s]+/', $p[2]);

        } else {
            return [$ext];

        }
    }

    protected function parseRecursive($params) {
        return preg_match('/(?<=^|\s)recursive(?:ly)?(?:=("|\')(true|yes|1|false|no|0)\1)?/', $params, $r) && (!isSet($r[2]) || in_array($r[2], ['true', 'yes', '1']));

    }

    public function __toString() {
        return $this->getContents();

    }

}
