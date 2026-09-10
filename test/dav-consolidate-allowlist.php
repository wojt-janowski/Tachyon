<?php

/**
 * Exercises the allow list of the dav-consolidate plugin.
 * No bootstrap: run it with `php test/dav-consolidate-allowlist.php`.
 */

require \dirname(__DIR__) . '/plugins/dav-consolidate/AllowList.php';

use Plugins\DavConsolidate\AllowList;

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

check('parses commas and whitespace alike',
	AllowList::parse("a@x.com, b@y.com\n c@z.com"), array('a@x.com', 'b@y.com', 'c@z.com'));
check('an empty setting parses to nothing',
	AllowList::parse('  '), array());

check('matches a full address',
	AllowList::allows('alice@example.com', array('alice@example.com')), true);
check('matches a bare domain',
	AllowList::allows('bob@example.com', array('example.com')), true);
check('is case insensitive',
	AllowList::allows('Alice@Example.COM', array('alice@example.com')), true);
check('rejects another domain',
	AllowList::allows('bob@example.org', array('example.com')), false);
check('rejects another address',
	AllowList::allows('carol@example.com', array('alice@example.com')), false);
check('an empty list allows nobody',
	AllowList::allows('alice@example.com', array()), false);
check('an empty address is never allowed',
	AllowList::allows('', array('example.com')), false);

echo $iFailures ? "\n{$iFailures} failure(s)\n" : "\nall passed\n";
exit($iFailures ? 1 : 0);
