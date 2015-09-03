<?php


namespace Minify;


class Helpers {

    public static function getPathAndQuery() {
        @list($path, $queryString) = explode('?', $_SERVER['REQUEST_URI'], 2);

        $scriptName = $_SERVER['SCRIPT_NAME'];
        $pos = strrpos($scriptName, '/');
        $basePath = substr($scriptName, 0, $pos);

        if ($basePath !== substr($path, 0, $pos)) {
            Header('HTTP/1.1 404 Not Found');
            exit;

        }

        return [substr($path, $pos), $queryString];

    }

    public static function sanitizePath($path) {
        $tmp = explode('/', trim($path, '/'));
        $path = [];

        while (!empty($tmp)) {
            $u = array_shift($tmp);
            if ($u === '.') {
                continue;

            } else if ($u === '..') {
                array_pop($path);

            } else {
                $path[] = $u;

            }
        }

        return '/' . implode('/', $path);

    }

}
