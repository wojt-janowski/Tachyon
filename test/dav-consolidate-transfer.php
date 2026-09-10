<?php

/**
 * Exercises the dav-consolidate transfer end to end against the fake DAV
 * server in test/fake-dav-server.php, which this script starts and stops
 * itself. No application bootstrap.
 *
 * Run with `php test/dav-consolidate-transfer.php`.
 */

define('APP_VERSION', '0.0.0');
define('TACHYON_LIBRARIES_PATH', \dirname(__DIR__) . '/tachyon/v/0.0.0/app/libraries/');

require __DIR__ . '/dav-discovery-log-stub.php';
require \dirname(__DIR__) . '/plugins/dav-consolidate/Consolidator.php';

\spl_autoload_register(function ($sClassName) {
	if (\str_starts_with($sClassName, 'Tachyon\\Util\\')) {
		$sFile = TACHYON_LIBRARIES_PATH . 'tachyon_util/' . \strtolower(\str_replace('\\', '/', \substr($sClassName, 13))) . '.php';
	} else {
		$sFile = TACHYON_LIBRARIES_PATH . \strtr($sClassName, '\\', '/') . '.php';
	}
	if (\is_file($sFile)) {
		include_once $sFile;
	}
});

use Plugins\DavConsolidate\Consolidator;

// ---- fake server lifecycle -------------------------------------------------

$sRoot = \sys_get_temp_dir() . '/dav-consolidate-' . \getmypid();
$iPort = 20000 + (\getmypid() % 10000);
\mkdir($sRoot, 0700, true);

$rServer = \proc_open(
	array(PHP_BINARY, '-S', "127.0.0.1:{$iPort}", __DIR__ . '/fake-dav-server.php'),
	array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
	$aPipes,
	null,
	array('FAKE_DAV_ROOT' => $sRoot)
);

\register_shutdown_function(function () use ($rServer, $sRoot) {
	\proc_terminate($rServer);
	\proc_close($rServer);
	\exec('rm -rf ' . \escapeshellarg($sRoot));
});

for ($i = 0; $i < 50; ++$i) {
	$rSock = @\fsockopen('127.0.0.1', $iPort, $iErr, $sErr, 0.1);
	if ($rSock) {
		\fclose($rSock);
		break;
	}
	\usleep(100000);
}

// ---- helpers ---------------------------------------------------------------

$iFailures = 0;

function check(string $sLabel, $mActual, $mExpected) : void
{
	global $iFailures;
	if ($mActual === $mExpected) {
		echo "PASS  {$sLabel}\n";
		return;
	}
	++$iFailures;
	echo "FAIL  {$sLabel}\n";
	echo '      expected: ' . \var_export($mExpected, true) . "\n";
	echo '      actual:   ' . \var_export($mActual, true) . "\n";
}

function seed(string $sCollection, array $aFiles) : void
{
	global $sRoot;
	\mkdir($sRoot . $sCollection, 0700, true);
	foreach ($aFiles as $sName => $sContent) {
		\file_put_contents($sRoot . $sCollection . $sName, $sContent);
	}
}

function files(string $sCollection) : array
{
	global $sRoot;
	if (!\is_dir($sRoot . $sCollection)) {
		return array();
	}
	$aNames = \array_values(\array_diff(\scandir($sRoot . $sCollection), array('.', '..')));
	\sort($aNames);
	return $aNames;
}

function content(string $sCollection, string $sName) : ?string
{
	global $sRoot;
	$sFile = $sRoot . $sCollection . $sName;
	return \is_file($sFile) ? \file_get_contents($sFile) : null;
}

function consolidator(bool $bApply) : Consolidator
{
	global $iPort;
	$oClient = new \Tachyon\Util\DAV\Client(array(
		'baseUri' => "http://127.0.0.1:{$iPort}",
		'userName' => 'alice',
		'password' => 'secret'
	));
	return new Consolidator($oClient, $bApply, function (string $sMsg) {
		if (\getenv('DAV_DISCOVERY_VERBOSE')) {
			\fwrite(STDERR, "[consolidate] {$sMsg}\n");
		}
	});
}

// ---- dry run leaves everything alone ---------------------------------------

seed('/dav/card/alice/default/', array('u2.vcf' => 'DEFAULT VERSION'));
seed('/dav/card/alice/old/', array('u1.vcf' => 'ONE', 'u2.vcf' => 'OLD VERSION', 'u3.vcf' => 'THREE'));
$aPaths = array('/dav/card/alice/old/', '/dav/card/alice/default/');

$aReport = consolidator(false)->consolidate($aPaths);

check('dry run: names the target',
	$aReport['target'], '/dav/card/alice/default/');
check('dry run: plans to copy what default lacks',
	$aReport['collections']['/dav/card/alice/old/']['copied'], array('u1.vcf', 'u3.vcf'));
check('dry run: notes what default already has',
	$aReport['collections']['/dav/card/alice/old/']['kept'], array('u2.vcf'));
check('dry run: deletes nothing',
	$aReport['collections']['/dav/card/alice/old/']['deleted'], false);
check('dry run: the source is untouched',
	files('/dav/card/alice/old/'), array('u1.vcf', 'u2.vcf', 'u3.vcf'));
check('dry run: the target is untouched',
	files('/dav/card/alice/default/'), array('u2.vcf'));

// ---- apply moves the contents and removes the source -----------------------

$aReport = consolidator(true)->consolidate($aPaths);

check('apply: default now holds everything',
	files('/dav/card/alice/default/'), array('u1.vcf', 'u2.vcf', 'u3.vcf'));
check('apply: copied content is byte-identical',
	content('/dav/card/alice/default/', 'u1.vcf'), 'ONE');
check('apply: an item default already had keeps default\'s version',
	content('/dav/card/alice/default/', 'u2.vcf'), 'DEFAULT VERSION');
check('apply: the source collection is gone',
	files('/dav/card/alice/old/'), array());
check('apply: the report says so',
	$aReport['collections']['/dav/card/alice/old/']['deleted'], true);
check('apply: the report lists what moved',
	$aReport['collections']['/dav/card/alice/old/']['copied'], array('u1.vcf', 'u3.vcf'));

// ---- a failed copy keeps the source ----------------------------------------

seed('/dav/cal/alice/default/', array());
seed('/dav/cal/alice/old/', array('ok.ics' => 'OK', 'reject-me.ics' => 'NOPE'));

$aReport = consolidator(true)->consolidate(array('/dav/cal/alice/default/', '/dav/cal/alice/old/'));

check('failed copy: the good item still moved',
	files('/dav/cal/alice/default/'), array('ok.ics'));
check('failed copy: the failure is reported',
	$aReport['collections']['/dav/cal/alice/old/']['failed'], array('reject-me.ics'));
check('failed copy: the source is kept, with everything still in it',
	files('/dav/cal/alice/old/'), array('ok.ics', 'reject-me.ics'));
check('failed copy: and the report says it was not deleted',
	$aReport['collections']['/dav/cal/alice/old/']['deleted'], false);

// ---- an empty redundant collection is simply removed -----------------------

seed('/dav/card/bob/default/', array('a.vcf' => 'A'));
seed('/dav/card/bob/empty/', array());

$aReport = consolidator(true)->consolidate(array('/dav/card/bob/default/', '/dav/card/bob/empty/'));

check('empty source: removed',
	files('/dav/card/bob/empty/'), array());
check('empty source: nothing copied',
	$aReport['collections']['/dav/card/bob/empty/']['copied'], array());
check('empty source: default untouched',
	files('/dav/card/bob/default/'), array('a.vcf'));

// ---- no default collection means nothing happens ---------------------------

seed('/dav/card/carol/one/', array('x.vcf' => 'X'));
seed('/dav/card/carol/two/', array('y.vcf' => 'Y'));

$aReport = consolidator(true)->consolidate(array('/dav/card/carol/one/', '/dav/card/carol/two/'));

check('no default: no target',
	$aReport['target'], null);
check('no default: nothing touched',
	array(files('/dav/card/carol/one/'), files('/dav/card/carol/two/')), array(array('x.vcf'), array('y.vcf')));

echo $iFailures ? "\n{$iFailures} failure(s)\n" : "\nall passed\n";
exit($iFailures ? 1 : 0);
