<?php

namespace Plugins\DavConsolidate;

/**
 * Discovery, borrowed from the providers. The CardDAV and CalDAV traits
 * declare colliding members, so one class cannot use both; hence two
 * probes. Their private discovery methods are usable here because a
 * trait's members belong to the composing class.
 *
 * Both traits expect logWrite, logMask and logException on the class.
 */
trait ProbeLogging
{
	public function logWrite(string $sDesc, int $iType = \LOG_INFO, string $sName = '') : void
	{
		\Tachyon\Util\Log::debug('dav-consolidate', "[{$sName}] {$sDesc}");
	}

	public function logMask(#[\SensitiveParameter] string $sSecret) : void
	{
		// The providers register the password with the application logger so it
		// is masked wherever it might surface. Outside the application, as in
		// test/dav-consolidate.php, there is no logger and nothing is written.
		if (\class_exists(\Tachyon\Api::class, false)) {
			\Tachyon\Api::Logger()->AddSecret($sSecret);
		}
	}

	public function logException(\Throwable $oException, int $iType = \LOG_ERR, string $sName = '') : void
	{
		\Tachyon\Util\Log::warning('dav-consolidate', "[{$sName}] " . \get_class($oException) . ': ' . $oException->getMessage());
	}
}

/**
 * @return array{0: \Tachyon\Util\DAV\Client, 1: string[]} the client, and the collection paths found
 */
class CardDavProbe
{
	use \Tachyon\Providers\AddressBook\CardDAV;
	use ProbeLogging;

	public function discover(string $sUrl, string $sUser, #[\SensitiveParameter] string $sPassword) : array
	{
		$oClient = $this->getDavClientFromUrl($sUrl, $sUser, $sPassword);
		$aPaths = $this->getContactsPaths($oClient, $oClient->urlPath, $sUser, $sPassword);

		return array($oClient, \array_keys($aPaths));
	}
}

class CalDavProbe
{
	use \Tachyon\Providers\Calendar\CalDAV;
	use ProbeLogging;

	public function discover(string $sUrl, string $sUser, #[\SensitiveParameter] string $sPassword) : array
	{
		$oClient = $this->getDavClientFromUrl($sUrl, $sUser, $sPassword);
		$aPaths = $this->getCalendarPaths($oClient, $oClient->urlPath, $sUser, $sPassword);

		return array($oClient, \array_keys($aPaths));
	}
}
