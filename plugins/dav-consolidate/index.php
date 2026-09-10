<?php

require_once __DIR__ . '/AllowList.php';
require_once __DIR__ . '/Consolidator.php';
require_once __DIR__ . '/Probes.php';

use Plugins\DavConsolidate\AllowList;
use Plugins\DavConsolidate\CalDavProbe;
use Plugins\DavConsolidate\CardDavProbe;
use Plugins\DavConsolidate\Consolidator;
use Tachyon\Providers\Storage\Enumerations\StorageType;

class DavConsolidatePlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'DAV consolidation',
		AUTHOR = 'Clinically',
		VERSION = '1.0',
		RELEASE = '2026-09-10',
		REQUIRED = '4.2.2',
		CATEGORY = 'Contacts',
		LICENSE = 'MIT',
		DESCRIPTION = 'Once per account, folds every CardDAV and CalDAV collection but "default" into "default" and removes it. For accounts on an allow list.';

	/** Config storage key recording that an account has been consolidated */
	const MARKER = 'dav_consolidated';

	public function Init() : void
	{
		$this->addHook('login.success', 'LoginSuccess');
	}

	/**
	 * Runs the consolidation for an allow-listed account at login, which is
	 * the only point at which a client authenticated as the user exists:
	 * Stalwart offers no administrative path to another account's
	 * collections, and the password is only available inside the session.
	 *
	 * Once every redundant collection is gone a marker is stored and the
	 * account is never looked at again. Until then it re-runs each login,
	 * which is what lets a dry run be read in the log before "apply" is
	 * switched on, and what retries a copy that failed.
	 */
	public function LoginSuccess(\Tachyon\Model\MainAccount $oAccount) : void
	{
		try {
			$aAllowList = AllowList::parse((string) $this->Config()->Get('plugin', 'allow_list', ''));
			if (!AllowList::allows($oAccount->Email(), $aAllowList)) {
				return;
			}

			$oStorage = $this->Manager()->Actions()->StorageProvider();
			if ($oStorage->Get($oAccount, StorageType::CONFIG, static::MARKER)) {
				return;
			}

			$sPassword = $oAccount->IncPassword();
			if ('' === $sPassword) {
				return;
			}

			$bApply = (bool) $this->Config()->Get('plugin', 'apply', false);
			$sEmail = $oAccount->Email();

			$aOutcome = array(
				'card' => $this->consolidate(new CardDavProbe(),
					(string) $this->Config()->Get('plugin', 'carddav_url', ''), $sEmail, $sPassword, $bApply),
				'cal' => $this->consolidate(new CalDavProbe(),
					(string) $this->Config()->Get('plugin', 'caldav_url', ''), $sEmail, $sPassword, $bApply)
			);

			// Both halves clean, and it really ran: remember, and never run again.
			if ($bApply && $aOutcome['card']['clean'] && $aOutcome['cal']['clean']) {
				$oStorage->Put($oAccount, StorageType::CONFIG, static::MARKER,
					\json_encode(array('when' => \time(), 'card' => $aOutcome['card']['report'], 'cal' => $aOutcome['cal']['report']),
						JSON_THROW_ON_ERROR));
				\Tachyon\Util\Log::info('dav-consolidate', "{$sEmail}: consolidated; will not run again for this account");
			}
		} catch (\Throwable $oException) {
			// Nothing about DAV may keep someone out of their mail.
			$this->Manager()->WriteException('dav-consolidate: ' . $oException->getMessage(), \LOG_ERR);
		}
	}

	/**
	 * One half, contacts or calendars.
	 *
	 * "clean" means there is nothing left to do for this half: no URL was
	 * configured, no redundant collections exist, or every one was removed.
	 * A collection that survived, for whatever reason, is not clean.
	 *
	 * @return array{clean: bool, report: ?array}
	 */
	private function consolidate(CardDavProbe|CalDavProbe $oProbe, string $sUrl, string $sEmail, #[\SensitiveParameter] string $sPassword, bool $bApply) : array
	{
		if ('' === $sUrl) {
			return array('clean' => true, 'report' => null);
		}

		list($oClient, $aPaths) = $oProbe->discover($sUrl, $sEmail, $sPassword);
		if (!$aPaths) {
			\Tachyon\Util\Log::warning('dav-consolidate', "{$sEmail}: discovery at {$sUrl} found no collections");
			return array('clean' => false, 'report' => null);
		}

		$oConsolidator = new Consolidator($oClient, $bApply, function (string $sMsg) use ($sEmail) {
			\Tachyon\Util\Log::info('dav-consolidate', "{$sEmail}: {$sMsg}");
		});
		$aReport = $oConsolidator->consolidate($aPaths);

		$bClean = null !== $aReport['target'];
		foreach ($aReport['collections'] as $aCollection) {
			$bClean = $bClean && $aCollection['deleted'];
		}

		return array('clean' => $bClean, 'report' => $aReport);
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('allow_list')
				->SetLabel('Allow list')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('Addresses or domains to consolidate, separated by commas or whitespace. Empty touches nobody. Start with one account and read the log.')
				->SetDefaultValue(''),

			\Tachyon\Plugins\Property::NewInstance('carddav_url')
				->SetLabel('CardDAV base URL')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Base URL for discovery, for example https://mail.example.com/dav/card. Empty leaves address books alone.')
				->SetDefaultValue(''),

			\Tachyon\Plugins\Property::NewInstance('caldav_url')
				->SetLabel('CalDAV base URL')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Base URL for discovery, for example https://mail.example.com/dav/cal. Empty leaves calendars alone.')
				->SetDefaultValue(''),

			\Tachyon\Plugins\Property::NewInstance('apply')
				->SetLabel('Apply changes')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Off: log what would be copied and removed, change nothing. On: do it, and mark the account done once every redundant collection is gone.')
				->SetDefaultValue(false)
		);
	}
}
