<?php
$findRoot = function ($root) {
    do {
        $lastRoot = $root;
        $root = dirname($root);
        if (is_dir($root . '/vendor/cakephp/cakephp')) {
            return $root;
        }
    } while ($root !== $lastRoot);

    throw new Exception("Cannot find the root of the application, unable to run tests");
};
$root = $findRoot(__FILE__);
unset($findRoot);

chdir($root);
if (file_exists($root . '/config/bootstrap.php')) {
    require $root . '/config/bootstrap.php';
}

require $root . '/vendor/cakephp/cakephp/tests/bootstrap.php';

\Cake\Core\Plugin::load('CakeSQS', ['path' => dirname(dirname(__FILE__)) . DS]);
