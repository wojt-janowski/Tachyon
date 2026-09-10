<?php

/**
 * A minimal WebDAV server for exercising collection transfers without a
 * real CardDAV or CalDAV host. Backed by a directory: collections are
 * subdirectories, items are files, and the etag is the file's hash.
 *
 *   FAKE_DAV_ROOT=/tmp/davroot php -S 127.0.0.1:8766 test/fake-dav-server.php
 *
 * Supports PROPFIND (depth 0 and 1), GET, PUT (honouring If-None-Match: *),
 * and DELETE on both items and collections. An item whose name starts with
 * "reject-" answers 500 to PUT, so a failed copy can be provoked.
 */

$sRoot = \rtrim((string) \getenv('FAKE_DAV_ROOT'), '/');
$sPath = \rawurldecode((string) \parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$sFile = $sRoot . $sPath;
$sMethod = $_SERVER['REQUEST_METHOD'];

if ('' === $sRoot || \str_contains($sPath, '..')) {
	\http_response_code(400);
	exit;
}

function multistatusEntry(string $sHref, bool $bCollection, string $sEtag = '') : string
{
	$sType = $bCollection ? '<d:collection/>' : '';
	$sEtagProp = $bCollection ? '' : "<d:getetag>\"{$sEtag}\"</d:getetag>";
	return "<d:response><d:href>{$sHref}</d:href><d:propstat><d:prop>"
		. "<d:resourcetype>{$sType}</d:resourcetype>{$sEtagProp}"
		. '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
}

switch ($sMethod) {
	case 'PROPFIND':
		if (!\file_exists($sFile)) {
			\http_response_code(404);
			exit;
		}
		$iDepth = (int) ($_SERVER['HTTP_DEPTH'] ?? 0);
		$sBody = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">';
		if (\is_dir($sFile)) {
			$sHref = \rtrim($sPath, '/') . '/';
			$sBody .= multistatusEntry($sHref, true);
			if (1 === $iDepth) {
				foreach (\scandir($sFile) as $sName) {
					if ('.' === $sName || '..' === $sName) {
						continue;
					}
					$sChild = $sFile . '/' . $sName;
					$sBody .= \is_dir($sChild)
						? multistatusEntry($sHref . \rawurlencode($sName) . '/', true)
						: multistatusEntry($sHref . \rawurlencode($sName), false, \md5_file($sChild));
				}
			}
		} else {
			$sBody .= multistatusEntry($sPath, false, \md5_file($sFile));
		}
		$sBody .= '</d:multistatus>';
		\http_response_code(207);
		\header('Content-Type: application/xml; charset=utf-8');
		echo $sBody;
		exit;

	case 'GET':
		if (!\is_file($sFile)) {
			\http_response_code(404);
			exit;
		}
		\header('Content-Type: ' . (\str_ends_with($sFile, '.ics') ? 'text/calendar' : 'text/vcard'));
		\header('ETag: "' . \md5_file($sFile) . '"');
		\readfile($sFile);
		exit;

	case 'PUT':
		if (\str_starts_with(\basename($sFile), 'reject-')) {
			\http_response_code(500);
			exit;
		}
		if (!\is_dir(\dirname($sFile))) {
			\http_response_code(409);
			exit;
		}
		if ('*' === ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') && \file_exists($sFile)) {
			\http_response_code(412);
			exit;
		}
		$bNew = !\file_exists($sFile);
		\file_put_contents($sFile, \file_get_contents('php://input'));
		\http_response_code($bNew ? 201 : 204);
		\header('ETag: "' . \md5_file($sFile) . '"');
		exit;

	case 'DELETE':
		if (\is_dir($sFile)) {
			$oIterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($sFile, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($oIterator as $oEntry) {
				$oEntry->isDir() ? \rmdir($oEntry->getPathname()) : \unlink($oEntry->getPathname());
			}
			\rmdir($sFile);
			\http_response_code(204);
			exit;
		}
		if (\is_file($sFile)) {
			\unlink($sFile);
			\http_response_code(204);
			exit;
		}
		\http_response_code(404);
		exit;
}

\http_response_code(405);
