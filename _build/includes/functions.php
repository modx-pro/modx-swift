<?php

/**
 * @param $filename
 *
 * @return string
 */
function getSnippetContent($filename)
{
    $file = trim(file_get_contents($filename));
    preg_match('#\<\?php(.*)#is', $file, $data);

    return rtrim(rtrim(trim($data[1]), '?>'));
}


/**
 * Recursive directory remove
 *
 * @param $dir
 */
function removeDir($dir)
{
    if (is_dir($dir)) {
        $objects = scandir($dir);

        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (filetype($dir . "/" . $object) == "dir") {
                    removeDir($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }

        reset($objects);
        rmdir($dir);
    }
}

/**
 * @param $base
 */
function cleanPackages($base)
{
    if ($dirs = @scandir($base)) {
        foreach ($dirs as $dir) {
            if (in_array($dir, array('.', '..'))) {
                continue;
            }
            $path = $base . $dir;
            if (is_dir($path)) {
                if (in_array(strtolower($dir), array('tests', 'docs', 'gui', 'doc', 'samples'))) {
                    removeDir($path);
                } else {
                    cleanPackages($path . '/');
                }
            } elseif (!in_array(pathinfo($path, PATHINFO_EXTENSION), array('php', 'pem'))) {
                unlink($path);
            }
        }
    }
}