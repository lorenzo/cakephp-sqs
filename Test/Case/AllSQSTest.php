<?php
class AllSQSTest extends PHPUnit_Framework_TestSuite {

	public static function suite() {
		$suite = new CakeTestSuite('All SQS plugin tests');
		$path = App::pluginPath('SQS');
		$testPath = $path . DS . 'Test' . DS . 'Case';
		if (!is_dir($testPath)) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testPath), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($iterator as $folder) {
			$folder = (string)$folder;
			$folderName = basename($folder);

			if ($folderName === '.' || $folderName === '..') {
				continue;
			}
			$suite->addTestDirectory($folder);
		}

		$suite->addTestDirectory($testPath);
		return $suite;
	}

}
