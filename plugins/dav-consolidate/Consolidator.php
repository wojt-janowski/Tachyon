<?php

namespace Plugins\DavConsolidate;

/**
 * Moves the contents of an account's redundant DAV collections into its
 * "default" one and removes them. The decisions are static and pure so
 * test/dav-consolidate-rules.php can exercise them with no bootstrap; the
 * transfer itself needs a DAV client authenticated as the user.
 */
class Consolidator
{
	private \Tachyon\Util\DAV\Client $oClient;
	private bool $bApply;
	/** @var callable */
	private $fnLog;

	/**
	 * @param bool $bApply false reports what would happen and changes nothing
	 * @param callable|null $fnLog receives one line per step
	 */
	public function __construct(\Tachyon\Util\DAV\Client $oClient, bool $bApply, ?callable $fnLog = null)
	{
		$this->oClient = $oClient;
		$this->bApply = $bApply;
		$this->fnLog = $fnLog ?: function (string $sMsg) {};
	}

	/**
	 * Folds every collection but "default" into "default", then removes it.
	 *
	 * Per redundant collection, in order: list both sides; copy each item
	 * default lacks, with If-None-Match so an item that appeared meanwhile
	 * is never overwritten; re-list default and confirm nothing is missing;
	 * only then DELETE the collection. Any failure before that last step
	 * leaves the collection in place and is reported.
	 *
	 * @return array{target: ?string, dry_run: bool, collections: array<string, array{copied: string[], kept: string[], failed: string[], deleted: bool}>}
	 */
	public function consolidate(array $aCollectionPaths) : array
	{
		$aReport = array(
			'target' => static::chooseTarget($aCollectionPaths),
			'dry_run' => !$this->bApply,
			'collections' => array()
		);

		if (null === $aReport['target']) {
			$this->log('no collection named "default" among ' . \implode(', ', $aCollectionPaths) . '; nothing to do');
			return $aReport;
		}

		foreach (static::redundant($aCollectionPaths, $aReport['target']) as $sSource) {
			$aReport['collections'][$sSource] = $this->fold($sSource, $aReport['target']);
		}

		return $aReport;
	}

	private function fold(string $sSource, string $sTarget) : array
	{
		$aResult = array('copied' => array(), 'kept' => array(), 'failed' => array(), 'deleted' => false);

		$aSourceItems = $this->listItems($sSource);
		$aTargetItems = $this->listItems($sTarget);

		$aResult['copied'] = static::toCopy($aSourceItems, $aTargetItems);
		$aResult['kept'] = \array_values(\array_intersect(\array_keys($aSourceItems), \array_keys($aTargetItems)));

		$this->log(\sprintf('%s: %d to copy into %s, %d already there%s',
			$sSource, \count($aResult['copied']), $sTarget, \count($aResult['kept']),
			$this->bApply ? '' : ' (dry run)'));

		if (!$this->bApply) {
			return $aResult;
		}

		foreach ($aResult['copied'] as $iIndex => $sName) {
			try {
				$this->copyItem($sSource, $sTarget, $sName);
			} catch (\Throwable $oException) {
				$this->log("{$sSource}{$sName}: copy failed: " . $oException->getMessage());
				$aResult['failed'][] = $sName;
				unset($aResult['copied'][$iIndex]);
			}
		}
		$aResult['copied'] = \array_values($aResult['copied']);

		// Trust the server, not our own bookkeeping, before removing anything.
		$aMissing = static::missing($aSourceItems, $this->listItems($sTarget));
		if ($aMissing) {
			$this->log("{$sSource}: kept, {$sTarget} still lacks " . \implode(', ', $aMissing));
			return $aResult;
		}

		try {
			$this->oClient->request('DELETE', $sSource);
			$aResult['deleted'] = true;
			$this->log("{$sSource}: removed");
		} catch (\Throwable $oException) {
			$this->log("{$sSource}: delete failed: " . $oException->getMessage());
		}

		return $aResult;
	}

	/**
	 * The items directly inside a collection, keyed by file name with the
	 * etag as value. Sub-collections and the collection itself are skipped.
	 */
	private function listItems(string $sPath) : array
	{
		$aItems = array();
		foreach ($this->oClient->propFind($sPath, array('{DAV:}resourcetype', '{DAV:}getetag'), 1) as $sHref => $aProps) {
			$bCollection = !empty($aProps['{DAV:}resourcetype'])
				&& \is_array($aProps['{DAV:}resourcetype'])
				&& \in_array('{DAV:}collection', $aProps['{DAV:}resourcetype'], true);
			if ($bCollection || !isset($aProps['{DAV:}getetag'])) {
				continue;
			}
			$sName = \rawurldecode(\basename(\rtrim($sHref, '/')));
			$aItems[$sName] = \trim((string) $aProps['{DAV:}getetag'], '"');
		}
		\ksort($aItems);
		return $aItems;
	}

	private function copyItem(string $sSource, string $sTarget, string $sName) : void
	{
		$sEncoded = \rawurlencode($sName);
		$oResponse = $this->oClient->request('GET', $sSource . $sEncoded);
		$sType = \str_ends_with(\strtolower($sName), '.ics') ? 'text/calendar' : 'text/vcard';
		$this->oClient->request('PUT', $sTarget . $sEncoded, $oResponse->body, array(
			"Content-Type: {$sType}; charset=utf-8",
			'If-None-Match: *'
		));
		$this->log("{$sSource}{$sName}: copied");
	}

	private function log(string $sMsg) : void
	{
		($this->fnLog)($sMsg);
	}

	/**
	 * The collection whose last path segment is "default". Stalwart creates
	 * that one itself; anything else on the account came from elsewhere.
	 */
	public static function chooseTarget(array $aPaths) : ?string
	{
		foreach ($aPaths as $sPath) {
			if ('default' === \basename(\rtrim($sPath, '/'))) {
				return $sPath;
			}
		}

		return null;
	}

	/**
	 * Every collection that is not the target.
	 */
	public static function redundant(array $aPaths, string $sTarget) : array
	{
		return \array_values(\array_filter($aPaths, fn($sPath) => $sPath !== $sTarget));
	}

	/**
	 * File names the source holds and the target does not. Both sides are
	 * listings keyed by file name, which is also the item's identity on the
	 * server: a UID is stored as <uid>.vcf or <uid>.ics.
	 */
	public static function toCopy(array $aSource, array $aTarget) : array
	{
		return \array_values(\array_diff(\array_keys($aSource), \array_keys($aTarget)));
	}

	/**
	 * What the target still lacks after the copy. Anything here means the
	 * source must stay.
	 */
	public static function missing(array $aSource, array $aTarget) : array
	{
		return static::toCopy($aSource, $aTarget);
	}
}
