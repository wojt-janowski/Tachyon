<?php

/**
 * Runs the dav-consolidate transfer against a real server as one account,
 * with no application bootstrap. Dry run unless --apply is given. This is
 * also the manual path for an account you would rather not put on the
 * plugin's allow list.
 *
 * Usage: php test/dav-consolidate.php <card|cal> <base-url> <user> <password> [--apply]
 *        DAV_DISCOVERY_VERBOSE=1 php test/dav-consolidate.php ...
 */

define('APP_VERSION', '0.0.0');

$sLib = \dirname(__DIR__) . '/tachyon/v/0.0.0/app/libraries';

require __DIR__ . '/dav-discovery-log-stub.php';

require $sLib . '/tachyon_util/dav/client.php';
require $sLib . '/tachyon_util/http/exception.php';
require $sLib . '/tachyon_util/http/response.php';
require $sLib . '/tachyon_util/http/request.php';
require $sLib . '/tachyon_util/http/request/curl.php';
require $sLib . '/tachyon_util/http/request/socket.php';
require $sLib . '/Tachyon/Providers/AddressBook/CardDAV.php';
require $sLib . '/Tachyon/Providers/Calendar/CalDAV.php';

require \dirname(__DIR__) . '/plugins/dav-consolidate/Consolidator.php';
require \dirname(__DIR__) . '/plugins/dav-consolidate/Probes.php';

use Plugins\DavConsolidate\CalDavProbe;
use Plugins\DavConsolidate\CardDavProbe;
use Plugins\DavConsolidate\Consolidator;

$aArgs = \array_values(\array_filter(\array_slice($argv, 1), fn($s) => '--apply' !== $s));
$bApply = \in_array('--apply', $argv, true);

if (\count($aArgs) < 4 || !\in_array($aArgs[0], array('card', 'cal'), true)) {
	\fwrite(STDERR, "usage: php test/dav-consolidate.php <card|cal> <base-url> <user> <password> [--apply]\n");
	exit(2);
}

$oProbe = 'card' === $aArgs[0] ? new CardDavProbe() : new CalDavProbe();
list($oClient, $aPaths) = $oProbe->discover($aArgs[1], $aArgs[2], $aArgs[3]);

if (!$aPaths) {
	echo "NO COLLECTIONS DISCOVERED\n";
	exit(1);
}

echo ($bApply ? 'APPLYING' : 'DRY RUN') . " for {$aArgs[2]}\n";
foreach ($aPaths as $sPath) {
	echo "  found {$sPath}\n";
}

$oConsolidator = new Consolidator($oClient, $bApply, function (string $sMsg) {
	echo "  {$sMsg}\n";
});
$aReport = $oConsolidator->consolidate($aPaths);

echo "\n" . \json_encode($aReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$bClean = null !== $aReport['target'];
foreach ($aReport['collections'] as $aCollection) {
	$bClean = $bClean && $aCollection['deleted'];
}
exit($bClean ? 0 : 1);
