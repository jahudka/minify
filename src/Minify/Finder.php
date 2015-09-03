<?php


namespace Minify;


class Finder extends \RecursiveDirectoryIterator {

    /**
     * @var bool
     */
    private $recursive;


    public static function find($from, $extensions = null, $recursive = true) {
        if ($extensions) {
            if (!is_array($extensions)) {
                $extensions = preg_split('/\s*,\s*/', (string) $extensions);

            }

            $extensions = '/\.(?:' . implode('|', array_map(function($s) { return preg_quote(ltrim($s, '.'), '/'); }, $extensions)) . ')$/i';

        }

        $tree = new static($from, $recursive);
        $files = new \RecursiveIteratorIterator($tree);

        if ($extensions) {
            $files = new \RegexIterator($files, $extensions, \RegexIterator::MATCH, \RegexIterator::USE_KEY);

        }

        return $files;

}

    public function __construct($path, $flags = null, $recursive = false) {
        if (is_bool($flags)) {
            $recursive = $flags;
            $flags = null;
        }

        if ($flags === null) {
            $flags = \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_FILEINFO;

        }

        $this->recursive = $recursive;

        parent::__construct($path, $flags);

    }

    /**
     * @return \RecursiveArrayIterator|static
     */
    public function getChildren() {
        if (!$this->recursive) {
            return new \RecursiveArrayIterator([]);

        }

        try {
            return new static($this->getPathname(), $this->getFlags());

        } catch(\UnexpectedValueException $e) {
            return new \RecursiveArrayIterator([]);

        }
    }


}
