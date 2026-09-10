<?php

namespace Plugins\DavConsolidate;

/**
 * Which accounts the plugin acts on. The same shape as the autoconfig
 * plugin's allow list, kept separate so this plugin does not depend on
 * that one's directory name.
 */
abstract class AllowList
{
	/**
	 * Commas and whitespace both separate, so a list can be pasted either way.
	 */
	public static function parse(string $sRaw) : array
	{
		$aParts = \preg_split('/[\s,]+/', $sRaw, -1, PREG_SPLIT_NO_EMPTY);

		return \is_array($aParts) ? \array_values($aParts) : array();
	}

	/**
	 * An entry matches a whole address or a bare domain.
	 */
	public static function allows(string $sEmail, array $aAllowList) : bool
	{
		$sEmail = \strtolower(\trim($sEmail));
		if ('' === $sEmail) {
			return false;
		}

		$sDomain = \substr((string) \strrchr($sEmail, '@'), 1);

		foreach ($aAllowList as $sEntry) {
			$sEntry = \strtolower(\trim((string) $sEntry));
			if ('' !== $sEntry && ($sEntry === $sEmail || ('' !== $sDomain && $sEntry === $sDomain))) {
				return true;
			}
		}

		return false;
	}
}
