<?php

/**
 * @file plugins/importexport/dnb/filter/DNBXmlFilter.inc.php
 *
 * Copyright (c) 2017 Center for Digital Systems (CeDiS), Freie Universität Berlin
 * Distributed under the GNU GPL v2. For full terms see the plugin file LICENSE.
 * Author: Bozana Bokan
 * Last update: May 15, 2017
 *
 * @class DNBXmlFilter
 * @ingroup plugins_importexport_dnb
 *
 * @brief Class that converts an Article to a DNB XML document.
 */

import('lib.pkp.plugins.importexport.native.filter.NativeExportFilter');


class DNBXmlFilter extends NativeExportFilter {
	/**
	 * Constructor
	 * @param $filterGroup FilterGroup
	 */
	function __construct($filterGroup) {
		$this->setDisplayName('DNB XML export');
		parent::__construct($filterGroup);
	}

	//
	// Implement template methods from PersistableFilter
	//
	/**
	 * @copydoc PersistableFilter::getClassName()
	 */
	function getClassName() {
		return 'plugins.importexport.dnb.filter.DNBXmlFilter';
	}

	//
	// Implement template methods from Filter
	//
	/**
	 * @see Filter::process()
	 * @param $pubObjects ArticleGalley
	 * @return DOMDocument
	 */
	function &process(&$pubObject) {
		// Create the XML document
		$doc = new DOMDocument('1.0', 'utf-8');
		$doc->preserveWhiteSpace = false;
		$doc->formatOutput = true;
		$deployment = $this->getDeployment();
		$journal = $deployment->getContext();
		$plugin = $deployment->getPlugin();
		$cache = $plugin->getCache();
		$request = Application::getRequest();

		// Get all objects
		$issue = $article = $galley = $galleyFile = null;
		$galley = $pubObject;
		$galleyFile = $galley->getFile();
		$articleId = $galley->getSubmissionId();
		if ($cache->isCached('articles', $articleId)) {
			$article = $cache->get('articles', $articleId);
		} else {
			$articleDao = DAORegistry::getDAO('PublishedArticleDAO'); /* @var $articleDao PublishedArticleDAO */
			$article = $articleDao->getByArticleId($pubObject->getSubmissionId(), $journal->getId());
			if ($article) $cache->add($article, null);
		}
		$issueId = $article->getIssueId();
		if ($cache->isCached('issues', $issueId)) {
			$issue = $cache->get('issues', $issueId);
		} else {
			$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
			$issue = $issueDao->getById($issueId, $journal->getId());
			if ($issue) $cache->add($issue, null);
		}

		// Data we will need later
		$language = AppLocale::get3LetterIsoFromLocale($galley->getLocale());
		$datePublished = $article->getDatePublished();
		if (!$datePublished) $datePublished = $issue->getDatePublished();
		assert(!empty($datePublished));
		$datePublishedShort = date('Y-m-d', strtotime($datePublished));
		$yearYYYY = date('Y', strtotime($datePublished));
		$yearYY = date('y', strtotime($datePublished));
		$month = date('m', strtotime($datePublished));
		$day = date('d', strtotime($datePublished));
		$authors = $article->getAuthors();
		if (is_array($authors) && !empty($authors)) {
			// get and remove first author from the array
			// so the array can be used later in the field 700 1 _
			$firstAuthor = array_shift($authors);
		}
		assert($firstAuthor);

		// is open access
		$openAccess = false;
		if ($journal->getSetting('publishingMode') == PUBLISHING_MODE_OPEN) {
			$openAccess = true;
		} else if ($journal->getSetting('publishingMode') == PUBLISHING_MODE_SUBSCRIPTION) {
			if ($issue->getAccessStatus() == 0 || $issue->getAccessStatus() == ISSUE_ACCESS_OPEN) {
				$openAccess = true;
			} else if ($issue->getAccessStatus() == ISSUE_ACCESS_SUBSCRIPTION) {
				if ($article->getAccessStatus() == ARTICLE_ACCESS_OPEN) {
					$openAccess = true;
				}
			}
		}
		assert($openAccess || $archiveAccess);

		// Create the root node
		$rootNode = $this->createRootNode($doc);
		$doc->appendChild($rootNode);

		// record node
		$recordNode = $doc->createElementNS($deployment->getNamespace(), 'record');
		$rootNode->appendChild($recordNode);

		// leader
		$recordNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'leader', '00000naa a22      u 4500'));

		// control fields: 007 and 008
		$recordNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'controlfield', 'cr|||||'));
		$node->setAttribute('tag', '007');
		$recordNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'controlfield', $yearYY.$month.$day.'s'.$yearYYYY.'||||'.$language));
		$node->setAttribute('tag', '008');

		// data fields:
		// URN
		$urn = $galley->getStoredPubId('other::urnDNB');
		if (empty($urn)) $urn = $galley->getStoredPubId('other::urn');
		if (!empty($urn)) {
			$urnDatafield024 = $this->createDatafieldNode($doc, $recordNode, '024', '7', ' ');
			$this->createSubfieldNode($doc, $urnDatafield024, 'a', $urn);
			$this->createSubfieldNode($doc, $urnDatafield024, '2', 'urn');
		}
		// DOI
		$doi = $galley->getStoredPubId('doi');
		if (!empty($doi)) {
			$doiDatafield024 = $this->createDatafieldNode($doc, $recordNode, '024', '7', ' ');
			$this->createSubfieldNode($doc, $doiDatafield024, 'a', $doi);
			$this->createSubfieldNode($doc, $doiDatafield024, '2', 'doi');
		}
		// language
		$datafield041 = $this->createDatafieldNode($doc, $recordNode, '041', ' ', ' ');
		$this->createSubfieldNode($doc, $datafield041, 'a', $language);
		// access to the archived article
		$datafield093 = $this->createDatafieldNode($doc, $recordNode, '093', ' ', ' ');
		if ($openAccess) {
			$this->createSubfieldNode($doc, $datafield093, 'b', 'b');
		} else {
			$this->createSubfieldNode($doc, $datafield093, 'b', $archiveAccess);
		}
		// first author
		$datafield100 = $this->createDatafieldNode($doc, $recordNode, '100', '1', ' ');
		$this->createSubfieldNode($doc, $datafield100, 'a', $firstAuthor->getFullName(true));
		$this->createSubfieldNode($doc, $datafield100, '4', 'aut');
		// title
		$title = $article->getTitle($galley->getLocale());
		if (empty($title)) $title = $article->getTitle($article->getLocale());
		assert(!empty($title));
		$datafield245 = $this->createDatafieldNode($doc, $recordNode, '245', '0', '0');
		$this->createSubfieldNode($doc, $datafield245, 'a', $title);
		// date published
		$datafield264 = $this->createDatafieldNode($doc, $recordNode, '264', ' ', ' ');
		$this->createSubfieldNode($doc, $datafield264, 'c', $datePublishedShort);
		// article level URN and DOI (only if galley level URN and DOI do not exist)
		if (empty($urn) && empty($doi)) {
			$articleURN = $article->getStoredPubId('other::urnDNB');
			if (empty($articleURN)) $articleURN = $article->getStoredPubId('other::urn');
			$articleDoi = $article->getStoredPubId('doi');
			if (!empty($articleURN) || !empty($articleDoi)) {
				$doiDatafield500 = $this->createDatafieldNode($doc, $recordNode, '500', ' ', ' ');
				if (!empty($articleURN)) $this->createSubfieldNode($doc, $doiDatafield500, 'a', 'URN: ' . $articleURN);
				if (!empty($articleDoi)) $this->createSubfieldNode($doc, $doiDatafield500, 'a', 'DOI: ' . $articleDoi);
			}
		}
		// copyright notice
		$copyrightNotice = $journal->getSetting('copyrightNotice', $galley->getLocale());
		if (empty($copyrightNotice)) $copyrightNotice = $journal->getSetting('copyrightNotice', $journal->getPrimaryLocale());
		if (!empty($copyrightNotice)) {
			$copyrightNotice = PKPString::html2text($copyrightNotice);
			if (strlen($copyrightNotice) > 999)  {
				$copyrightNotice = substr($copyrightNotice, 0, 996);
				$copyrightNotice .= '...';
			}
			$datafield506 = $this->createDatafieldNode($doc, $recordNode, '506', ' ', ' ');
			$this->createSubfieldNode($doc, $datafield506, 'a', $copyrightNotice);
		}
		// abstract
		$abstract = $article->getAbstract($galley->getLocale());
		if (empty($abstract)) $abstract = $article->getAbstract($article->getLocale());
		if (!empty($abstract)) {
			$abstract = PKPString::html2text($abstract);
			if (strlen($abstract) > 999)  {
				$abstract = substr($abstract, 0, 996);
				$abstract .= '...';
			}
			$datafield520 = $this->createDatafieldNode($doc, $recordNode, '520', '3', ' ');
			$this->createSubfieldNode($doc, $datafield520, 'a', $abstract);
		}
		// license URL
		$licenseURL = $article->getLicenseURL();
		if (empty($licenseURL)) {
			$licenseURL = $journal->getSetting('licenseURL');
		}
		if (!empty($licenseURL)) {
			$datafield540 = $this->createDatafieldNode($doc, $recordNode, '540', ' ', ' ');
			$this->createSubfieldNode($doc, $datafield540, 'u', $licenseURL);
		}
		// keywords
		$supportedLocales = array_keys(AppLocale::getSupportedFormLocales());
		$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO'); /* @var $submissionKeywordDao SubmissionKeywordDAO */
		$controlledVocabulary = $submissionKeywordDao->getKeywords($article->getId(), array($galley->getLocale()));
		if (!empty($controlledVocabulary[$galley->getLocale()])) {
			$datafield653 = $this->createDatafieldNode($doc, $recordNode, '653', ' ', ' ');
			foreach ($controlledVocabulary[$galley->getLocale()] as $controlledVocabularyItem) {
				$this->createSubfieldNode($doc, $datafield653, 'a', $controlledVocabularyItem);
			}
		}
		// other authors
		foreach ((array) $authors as $author) {
			$datafield700 = $this->createDatafieldNode($doc, $recordNode, '700', '1', ' ');
			$this->createSubfieldNode($doc, $datafield700, 'a', $author->getFullName(true));
			$this->createSubfieldNode($doc, $datafield700, '4', 'aut');
		}
		// issue data
		$volume = $issue->getVolume();
		$number = $issue->getNumber();
		// at least the year has to be provided
		$issueYear = $issue->getYear();
		if (empty($issueYear)) $issueYear = $yearYYYY;
		assert($issueYear);
		$issueDatafield773 = $this->createDatafieldNode($doc, $recordNode, '773', '1', ' ');
		if (!empty($volume)) $this->createSubfieldNode($doc, $issueDatafield773, 'g', 'volume:'.$volume);
		if (!empty($number)) $this->createSubfieldNode($doc, $issueDatafield773, 'g', 'number:'.$number);
		$this->createSubfieldNode($doc, $issueDatafield773, 'g', 'year:'.$issueYear);
		$this->createSubfieldNode($doc, $issueDatafield773, '7', 'nnas');
		// journal data
		// there have to be an ISSN
		$issn = $journal->getSetting('onlineIssn');
		if (empty($issn)) $issn = $journal->getSetting('printIssn');
		assert(!empty($issn));
		$journalDatafield773 = $this->createDatafieldNode($doc, $recordNode, '773', '1', '8');
		$this->createSubfieldNode($doc, $journalDatafield773, 'x', $issn);
		// file data
		$galleyURL = $request->url(null, 'article', 'view', array($article->getId(), $galley->getId()));
		$datafield856 = $this->createDatafieldNode($doc, $recordNode, '856', '4', ' ');
		$this->createSubfieldNode($doc, $datafield856, 'u', $galleyURL);
		$this->createSubfieldNode($doc, $datafield856, 'q', $this->_getGalleyFileType($galley));
		$fileSize = $galleyFile->getFileSize();
		if ($fileSize > 0) $this->createSubfieldNode($doc, $datafield856, 's', $this->_getFileSize($fileSize));
		if ($openAccess) $this->createSubfieldNode($doc, $datafield856, 'z', 'Open Access');

		return $doc;
	}

	/**
	 * Create and return the root node.
	 * @param $doc DOMDocument
	 * @return DOMElement
	 */
	function createRootNode($doc) {
		$deployment = $this->getDeployment();
		$rootNode = $doc->createElementNS($deployment->getNamespace(), $deployment->getRootElementName());
		$rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $deployment->getXmlSchemaInstance());
		$rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());
		return $rootNode;
	}

	/**
	 * Generate the datafield node.
	 * @param $doc DOMElement
	 * @param $recordNode DOMElement
	 * @param $tag string 'tag' attribute
	 * @param $ind1 string 'ind1' attribute
	 * @param $ind2 string 'ind2' attribute
	 * @return DOMElement
	 */
	function createDatafieldNode($doc, $recordNode, $tag, $ind1, $ind2) {
		$deployment = $this->getDeployment();
		$datafieldNode = $doc->createElementNS($deployment->getNamespace(), 'datafield');
		$datafieldNode->setAttribute('tag', $tag);
		$datafieldNode->setAttribute('ind1', $ind1);
		$datafieldNode->setAttribute('ind2', $ind2);
		$recordNode->appendChild($datafieldNode);
		return $datafieldNode;
	}

	/**
	 * Generate the subfield node.
	 * @param $doc DOMElement
	 * @param $datafieldNode DOMElement
	 * @param $code string 'code' attribute
	 * @param $value string Element text value
	 */
	function createSubfieldNode($doc, $datafieldNode, $code, $value) {
		$deployment = $this->getDeployment();
		$datafieldNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'subfield', $value));
		$node->setAttribute('code', $code);
	}

	/**
	 * Generate the DNB file type.
	 * @param $galley ArticleGalley
	 * @return string pdf or epub (currently supported by DNB)
	 */
	function _getGalleyFileType($galley) {
		if ($galley->isPdfGalley()) {
			return 'pdf';
		} elseif ($galley->getFileType() == 'application/epub+zip') {
			return 'epub';
		}
		assert(false);
	}

	/**
	 * Get human friendly file size.
	 * @param $fileSize integer
	 * @return string
	 */
	function _getFileSize($fileSize) {
		$fileSize = round(((int)$fileSize) / 1024);
		if ($fileSize >= 1024) {
			$fileSize = round($fileSize / 1024, 2);
			$fileSize = $fileSize . ' MB';
		} elseif ($fileSize >= 1) {
			$fileSize = $fileSize . ' kB';
		}
		return $fileSize;
	}

}

?>
