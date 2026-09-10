<?php

/**
 * Calendar sync only visits the calendars the server still lists, so a
 * collection deleted on the server was never retired locally and lingered
 * as a ghost. This exercises PdoCalendar::retireVanishedCalendars() against
 * an in-memory sqlite, with no application bootstrap.
 *
 * Run with `php test/calendar-vanished.php`.
 */

define('APP_VERSION', '0.0.0');

// Calendar::jsonSerialize() lacks a return type, which PHP reports as deprecated.
\error_reporting(E_ALL & ~E_DEPRECATED);
define('TACHYON_LIBRARIES_PATH', \dirname(__DIR__) . '/tachyon/v/0.0.0/app/libraries/');

// Before anything that logs, so Tachyon\Util\Log resolves to the stub.
require __DIR__ . '/dav-discovery-log-stub.php';

// The application's own autoloader, minus the legacy shims.
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

/**
 * PdoCalendar's constructor reads the application config and the session
 * to build a DSN. This bypasses that with an in-memory sqlite.
 */
class TestCalendar extends \Tachyon\Providers\Calendar\PdoCalendar
{
	public function __construct()
	{
		$oSettings = new \Tachyon\Pdo\Settings;
		$oSettings->driver = 'sqlite';
		$oSettings->dsn = 'sqlite::memory:';

		$oProp = new \ReflectionProperty(\Tachyon\Providers\Calendar\PdoCalendar::class, 'settings');
		$oProp->setValue($this, $oSettings);
	}

	public function logWrite(string $sDesc, int $iType = \LOG_INFO, string $sName = '', bool $bDiplayCrLf = false) : bool
	{
		if (\getenv('DAV_DISCOVERY_VERBOSE')) {
			\fwrite(STDERR, "[{$sName}] {$sDesc}\n");
		}
		return true;
	}

	public function seedCalendar(string $sDavPath, string $sName) : int
	{
		$this->GetCalendars(); // creates the schema
		$this->prepareAndExecute(
			'INSERT INTO tachyon_cal_calendars (id_user, uuid, name, dav_path, changed)'
			. ' VALUES (:id_user, :uuid, :name, :dav_path, :changed)',
			array(
				':id_user' => array($this->userId(), \PDO::PARAM_INT),
				':uuid' => array(\sha1($sDavPath ?: $sName), \PDO::PARAM_STR),
				':name' => array($sName, \PDO::PARAM_STR),
				':dav_path' => array($sDavPath, \PDO::PARAM_STR),
				':changed' => array(\time(), \PDO::PARAM_INT)
			)
		);
		return (int) $this->lastInsertId('tachyon_cal_calendars', 'id_calendar');
	}

	public function seedEvent(int $iCalendarId, string $sUid) : void
	{
		$this->prepareAndExecute(
			'INSERT INTO tachyon_cal_events (id_calendar, id_user, uid, etag, changed)'
			. " VALUES (:id_calendar, :id_user, :uid, 'abc', :changed)",
			array(
				':id_calendar' => array($iCalendarId, \PDO::PARAM_INT),
				':id_user' => array($this->userId(), \PDO::PARAM_INT),
				':uid' => array($sUid, \PDO::PARAM_STR),
				':changed' => array(\time(), \PDO::PARAM_INT)
			)
		);
	}

	public function countEvents(int $iCalendarId, int $iDeleted = 0) : int
	{
		$oStmt = $this->prepareAndExecute(
			'SELECT COUNT(*) FROM tachyon_cal_events WHERE id_calendar = :id_calendar AND deleted = :deleted',
			array(
				':id_calendar' => array($iCalendarId, \PDO::PARAM_INT),
				':deleted' => array($iDeleted, \PDO::PARAM_INT)
			)
		);
		return (int) $oStmt->fetchColumn();
	}

	public function clear() : void
	{
		$this->prepareAndExecute('DELETE FROM tachyon_cal_events');
		$this->prepareAndExecute('DELETE FROM tachyon_cal_calendars');
	}

	public function retire(array $aRemotePaths) : int
	{
		return $this->retireVanishedCalendars($aRemotePaths);
	}

	private function userId() : int
	{
		$oProp = new \ReflectionProperty(\Tachyon\Providers\Calendar\PdoCalendar::class, 'iUserID');
		return (int) $oProp->getValue($this);
	}
}

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

function visibleNames(TestCalendar $oCal) : array
{
	return \array_map(fn($o) => $o->Name, $oCal->GetCalendars());
}

// One store, emptied between scenarios: SyncDatabase() remembers in a static
// that the schema exists, so a second in-memory database would never get one.
$oStore = new TestCalendar();
$oStore->SetEmail('alice@example.com');
function fresh() : TestCalendar
{
	global $oStore;
	$oStore->clear();
	return $oStore;
}

$oCal = fresh();
$iGone = $oCal->seedCalendar('/dav/cal/alice/mWl8DfgUyt/', 'Migrated');
$oCal->seedEvent($iGone, 'event-1');
$oCal->seedEvent($iGone, 'event-2');
$iKept = $oCal->seedCalendar('/dav/cal/alice/default/', 'Default');
$oCal->seedEvent($iKept, 'event-3');
$iLocal = $oCal->seedCalendar('', 'Local only');

check('reports one calendar retired',
	$oCal->retire(array('/dav/cal/alice/default/')), 1);
check('a calendar gone from the server no longer shows, the others still do',
	visibleNames($oCal), array('Default', 'Local only'));
check('the retired calendar\'s events are hidden',
	$oCal->countEvents($iGone), 0);
check('but kept on disk, marked deleted, so a last local copy is never destroyed',
	$oCal->countEvents($iGone, 1), 2);
check('the surviving calendar keeps its events',
	$oCal->countEvents($iKept), 1);

$oCal = fresh();
$iOne = $oCal->seedCalendar('/dav/cal/alice/one/', 'One');
$oCal->seedEvent($iOne, 'event-1');
check('an empty remote list retires nothing, as a guard against a failed discovery',
	$oCal->retire(array()), 0);
check('and the calendar is still visible',
	visibleNames($oCal), array('One'));

$oCal = fresh();
$oCal->seedCalendar('/dav/cal/alice/default/', 'Default');
check('a calendar still on the server is not retired',
	$oCal->retire(array('/dav/cal/alice/default/', '/dav/cal/alice/other/')), 0);

$oCal = fresh();
$oCal->seedCalendar('', 'Local only');
check('a local-only calendar survives any remote list',
	$oCal->retire(array('/dav/cal/alice/default/')), 0);
check('and is still visible',
	visibleNames($oCal), array('Local only'));

echo $iFailures ? "\n{$iFailures} failure(s)\n" : "\nall passed\n";
exit($iFailures ? 1 : 0);
