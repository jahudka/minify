<?php


namespace Minify;


use JShrink\Minifier,
    lessc;

class Application {

    /** @var array */
    public static $defaults = [
        'sourceDir' => null,
        'outputDir' => null,
        'vendorDir' => null,
        'tempDir' => '/tmp',
        'debug' => false,
    ];

    /** @var array */
    public $onRequest = [];

    /** @var array */
    public $onNotFound = [];

    /** @var array */
    public $onInvalidRequest = [];

    /** @var array */
    public $onCompile = [];

    /** @var array */
    private $config;

    /** @var lessc */
    private $less;


    /**
     * @param array $config
     */
    public function __construct(array $config) {
        $this->config = $config + self::$defaults;

        if ($this->config['sourceDir'] === null) {
            $this->config['sourceDir'] = PHP_SAPI === 'cli' ? getcwd() : $_SERVER['DOCUMENT_ROOT'];

        }

        if ($this->config['vendorDir'] === null) {
            $dir = explode('/', trim(__DIR__, '/'));

            if (in_array('vendor', $dir)) {
                while (array_pop($dir) !== 'vendor'); // intentionally empty loop
                $this->config['vendorDir'] = '/' . implode('/', $dir) . '/vendor';

            } else {
                $this->config['vendorDir'] = false;

            }
        }
    }

    /**
     * @return bool
     */
    public function isDebug() {
        return $this->config['debug'];

    }

    /**
     * Handles a HTTP request. Checks if a cached version
     * matching the request already exists, falling back
     * to recompiling the source files and caching them
     * if a cached version doesn't exist.
     */
    public function handleRequest() {
        list ($path, $query) = Helpers::getPathAndQuery();

        $request = new \stdClass();
        $request->path = Helpers::sanitizePath($path);
        $request->query = $query;

        if (!preg_match('#\.min\.(js|css|less)$#', $request->path)) {
            $this->dispatchEvent('invalidRequest', $request);
            Header('HTTP/1.1 404 Not Found');
            exit;

        }

        $this->dispatchEvent('request', $request);

        $request->path = $this->config['sourceDir'] . $request->path;

        if (!file_exists($request->path)) {
            $this->dispatchEvent('notFound', $request->path);

            Header('HTTP/1.1 404 Not Found');
            exit;

        }

        if ($cached = $this->getCached($request->path, $request, true)) {
            if ($cached === true) {
                Header('HTTP/1.1 304 Not Modified');

            } else {
                $this->sendHeaders($request->path);
                fpassthru($cached);

            }
        } else {
            $source = $this->prepareSource($request->path);
            $this->dispatchEvent('compile', $source);

            $this->sendHeaders($request->path);

            try {
                $this->cache($source, $request);

            } catch (\Exception $e) {
                if ($this->config['debug']) {
                    if ($this->getExtension($request->path) === 'js') {
                        echo "\n\nwindow.setTimeout(function(){console.error(" . json_encode($e->getMessage()) . ");},1);\n\n";

                    } else {
                        $msg = strtr($e->getMessage(), ["\n" => '\A', '"' => '\"']);
                        echo "\n\nbody:before{position:fixed;left:0;top:0;z-index:10000000;width:100%;text-align:center;white-space:pre-line;background:#000;color:#fff;padding:4px 0;font:bold 14px/17px Arial,sans-serif;content:\"$msg\";}\n\n";

                    }
                }
            }

            echo $source->getContents();

        }

        exit;

    }

    /**
     * @param string $path
     * @param mixed $meta
     */
    public function compileSource($path, $meta = null) {
        if (!is_file($path)) {
            $this->dispatchEvent('notFound', $path);
            $this->perr("File '$path' doesn't exist or isn't readable by Minify\n");
            exit (-1);

        }

        if ($cached = $this->getCached($path, $meta)) {
            if (is_resource($cached)) {
                fclose($cached);

            }

            $this->perr("A valid cached version found.\n");

        } else {
            $localPath = $this->getLocalPath($path);
            $source = $this->prepareSource($path);
            $this->dispatchEvent('compile', $source);

            try {
                $this->cache($source, $meta);

            } catch (\Exception $e) {
                $this->perr($e->getMessage() . "\n");
                exit (-1);

            }

            $this->perr("File $localPath compiled successfully.\n");

            $outputPath = $this->config['outputDir'] . '/' . $localPath;
            @mkdir(dirname($outputPath), 0775, true);
            file_put_contents($outputPath, $source->getContents());

        }
    }


    protected function getLocalPath($path) {
        if (substr($path, 0, $n = strlen($this->config['sourceDir'])) === $this->config['sourceDir']) {
            return ltrim(substr($path, $n), '/');

        } else {
            return basename($path);

        }
    }


    protected function prepareSource($path) {
        $src = new Source($path);

        if ($this->config['vendorDir']) {
            $src->setVendorDir($this->config['vendorDir']);

        }

        if (!$this->config['debug']) {
            $src->addFilter('\.js$', function($data) { return Minifier::minify($data); });

        }

        $src->addFilter('\.less$', function($data) { return $this->getLess()->parse($data); });

        if (!$this->config['debug']) {
            $src->addFilter('\.(css|less)$', function($data) { return CSSCompressor::process($data); });

        }

        return $src;

    }

    protected function getCached($path, $meta = null, $ifModSince = null) {
        $cached = $this->config['tempDir'] . '/' . ($this->config['debug'] ? 'dbg-' : 'min-') . sha1($path);
        $rebuild = true;

        if (file_exists($cached)) {
            $rebuild = false;
            $fp = fopen($cached, 'r');
            $info = json_decode(trim(fgets($fp)), true);

            if ($info['__key'] !== sha1(json_encode($meta))) {
                $rebuild = true;
                $this->perr('cached file has mismatching meta key, rebuilding');

            } else {
                foreach ($info['__files'] as $dep) {
                    if (filemtime($dep) > $info['__lastMod']) {
                        $rebuild = true;
                        $this->perr("file $dep has changed since cached version was created, rebuilding");
                        break;

                    }
                }
            }
        }

        if ($rebuild) {
            if (isSet($fp)) {
                fclose($fp);

            }

            $this->perr($cached . "\n");
            return false;

        }

        if ($ifModSince) {
            $ifModSince = isSet($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;

            if ($info['__lastMod'] <= $ifModSince) {
                fclose($fp);
                return true;

            }

            Header('Last-Modified: ' . @date(DATE_RFC1123, $info['__lastMod']));

        }

        return $fp;

    }

    protected function cache(Source $source, $meta = null) {
        $info = [
            '__files' => $source->getDependencies(),
            '__lastMod' => $source->getLastModified(),
            '__key' => sha1(json_encode($meta)),
        ];

        $cached = $this->config['tempDir'] . '/' . ($this->config['debug'] ? 'dbg-' : 'min-') . sha1($source->getPath());

        if (!@file_put_contents($cached, json_encode($info) . "\n" . $source->getContents())) {
            throw new \LogicException('Minify: cannot save cached version of file ' . $source->getPath());

        }
    }

    protected function sendHeaders($path) {
        switch ($this->getExtension($path)) {
            case 'js':
                Header('Content-Type: text/javascript; charset=utf-8');
                break;

            case 'less':
            case 'css':
                Header('Content-Type: text/css; charset=utf-8');
                break;

        }
    }

    protected function getLess() {
        if (!isSet($this->less)) {
            $this->less = new lessc();
            $this->less->setPreserveComments(true);

        }

        return $this->less;

    }

    protected function getExtension($path) {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));

    }

    protected function dispatchEvent($name, $params) {
        $name = 'on' . ucfirst($name);

        if (!isSet($this->$name) || !is_array($this->$name)) {
            throw new \LogicException('Unknown event: ' . $name);

        }

        if (!is_array($params)) {
            $params = array_slice(func_get_args(), 1);

        }

        foreach ($this->$name as $handler) {
            if (!is_callable($handler)) {
                throw new \LogicException('Invalid event handler, expected callable, got ' . gettype($handler));

            }

            call_user_func_array($handler, $params);

        }
    }

    protected function perr($s) {
        if (is_resource(STDERR)) {
            fputs(STDERR, $s);

        } else {
            file_put_contents('php://stderr', $s);

        }
    }

}