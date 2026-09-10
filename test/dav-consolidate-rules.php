<?php

/**
 * Exercises the pure decisions of the dav-consolidate plugin: which
 * collection is the target, and which items need copying into it.
 * No bootstrap, no framework: run it with `php test/dav-consolidate-rules.php`.
 */

require \dirname(__DIR__) . '/plugins/dav-consolidate/Consolidator.php';

use Plugins\DavConsolidate\Consolidator;

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

// chooseTarget: the collection whose last segment is "default"

check('picks the collection named default',
	Consolidator::chooseTarget(array(
		'/dav/card/alice%40example.com/mWl8DfgUyt/',
		'/dav/card/alice%40example.com/default/',
	)),
	'/dav/card/alice%40example.com/default/');
check('is not fooled by "default" appearing earlier in the path',
	Consolidator::chooseTarget(array(
		'/dav/card/default/other/',
		'/dav/card/default/default/',
	)),
	'/dav/card/default/default/');
check('tolerates a missing trailing slash',
	Consolidator::chooseTarget(array('/dav/cal/alice/default')),
	'/dav/cal/alice/default');
check('returns null when no collection is named default',
	Consolidator::chooseTarget(array('/dav/card/alice/one/', '/dav/card/alice/two/')),
	null);
check('returns null for an empty list',
	Consolidator::chooseTarget(array()), null);

// redundant: every collection that is not the target

check('the other collections are the redundant ones',
	Consolidator::redundant(array('/a/default/', '/a/x/', '/a/y/'), '/a/default/'),
	array('/a/x/', '/a/y/'));
check('an account with only default has nothing redundant',
	Consolidator::redundant(array('/a/default/'), '/a/default/'),
	array());

// toCopy: items in the source the target does not already hold, keyed by
// the item's file name, which is what both sides list

$aSource = array('u1.vcf' => 'etag-a', 'u2.vcf' => 'etag-b', 'u3.vcf' => 'etag-c');
$aTarget = array('u2.vcf' => 'etag-zzz');

check('copies what the target lacks and leaves what it already has',
	Consolidator::toCopy($aSource, $aTarget), array('u1.vcf', 'u3.vcf'));
check('an empty source copies nothing',
	Consolidator::toCopy(array(), $aTarget), array());
check('an empty target copies everything',
	Consolidator::toCopy($aSource, array()), array('u1.vcf', 'u2.vcf', 'u3.vcf'));

// missing: what a re-listed target still lacks after the copy, which is
// what decides whether the source may be deleted

check('nothing missing means the source can go',
	Consolidator::missing($aSource, $aSource), array());
check('an item the target still lacks blocks deletion',
	Consolidator::missing($aSource, array('u1.vcf' => 'x', 'u2.vcf' => 'y')), array('u3.vcf'));

echo $iFailures ? "\n{$iFailures} failure(s)\n" : "\nall passed\n";
exit($iFailures ? 1 : 0);
