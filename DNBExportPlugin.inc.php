<?php

/**
 * @file plugins/importexport/dnb/DNBExportPlugin.inc.php
 *
 * Copyright (c) 2017 Center for Digital Systems (CeDiS), Freie Universität Berlin
 * Distributed under the GNU GPL v2. For full terms see the plugin file LICENSE.
 * Author: Bozana Bokan
 * Last update: Mary 15, 2017
 *
 * @class DNBExportPlugin
 * @ingroup plugins_importexport_dnb
 *
 * @brief DNB export plugin
 */

import('classes.plugins.PubObjectsExportPlugin');
import('lib.pkp.classes.file.FileManager');

define('DNB_STATUS_DEPOSITED', 'deposited');

class DNBExportPlugin extends PubObjectsExportPlugin {
	/**
	 * Constructor
	 */
	function __construct() {
		parent::__construct();
	}

	/**
	 * @copydoc Plugin::getName()
	 */
	function getName() {
		return 'DNBExportPlugin';
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.importexport.dnb.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.importexport.dnb.description');
	}

	/**
	 * @copydoc ImportExportPlugin::display()
	 */
	function display($args, $request) {
		parent::display($args, $request);
		$context = $request->getContext();
		switch (array_shift($args)) {
			case 'index':
			case '':
				$checkForTarResult = $this->checkForTar();
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign('checkTar', !is_array($checkForTarResult));
				$templateMgr->assign('checkSettings', $this->checkPluginSettings($context));
				$templateMgr->display($this->getTemplatePath() . 'index.tpl');
				break;
		}
	}

	/**
	 * @copydoc PubObjectsExportPlugin::getStatusNames()
	 */
	function getStatusNames() {
		return array(
			EXPORT_STATUS_ANY => __('plugins.importexport.common.status.any'),
			EXPORT_STATUS_NOT_DEPOSITED => __('plugins.importexport.dnb.status.non'),
			DNB_STATUS_DEPOSITED => __('plugins.importexport.dnb.status.deposited'),
			EXPORT_STATUS_MARKEDREGISTERED => __('plugins.importexport.common.status.markedRegistered'),
		);
	}

	/**
	 * @copydoc PubObjectsExportPlugin::getExportActionNames()
	 */
	function getExportActionNames() {
		return array_merge(parent::getExportActionNames(), array(
			EXPORT_ACTION_DEPOSIT => __('plugins.importexport.dnb.deposit'),
		));
	}

	/**
	 * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
	 */
	function getPluginSettingsPrefix() {
		return 'dnb';
	}

	/**
	 * @copydoc PubObjectsExportPlugin::getSubmissionFilter()
	 */
	function getSubmissionFilter() {
		return 'galley=>dnb-xml';
	}

	/**
	 * @copydoc PubObjectsExportPlugin::getExportActions()
	 */
	function getExportActions($context) {
		$actions = array(EXPORT_ACTION_EXPORT, EXPORT_ACTION_MARKREGISTERED);
		if ($this->getSetting($context->getId(), 'username') &&
			$this->getSetting($context->getId(), 'password') &&
			$this->getSetting($context->getId(), 'folderId')) {
			array_unshift($actions, EXPORT_ACTION_DEPOSIT);
		}
		return $actions;
	}

	/**
	 * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
	 */
	function getExportDeploymentClassName() {
		return 'DNBExportDeployment';
	}

	/**
	 * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
	 */
	function getSettingsFormClassName() {
		return 'DNBSettingsForm';
	}

	/**
	 * @copydoc PubObjectsExportPlugin::getDepositSuccessNotificationMessageKey()
	 */
	function getDepositSuccessNotificationMessageKey() {
		return 'plugins.importexport.dnb.deposited.success';
	}

	/**
	 * @copydoc PubObjectsExportPlugin::depositXML()
	 */
	function depositXML($object, $context, $filename) {
		$errors = array();
		$curlCh = curl_init();
		if ($httpProxyHost = Config::getVar('proxy', 'http_host')) {
			curl_setopt($curlCh, CURLOPT_PROXY, $httpProxyHost);
			curl_setopt($curlCh, CURLOPT_PROXYPORT, Config::getVar('proxy', 'http_port', '80'));
			if ($username = Config::getVar('proxy', 'username')) {
				curl_setopt($curlCh, CURLOPT_PROXYUSERPWD, $username . ':' . Config::getVar('proxy', 'password'));
			}
		}
		curl_setopt($curlCh, CURLOPT_UPLOAD, true);
		curl_setopt($curlCh, CURLOPT_HEADER, true);
		curl_setopt($curlCh, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curlCh, CURLOPT_PROTOCOLS, CURLPROTO_SFTP);

		assert(is_readable($filename));
		$fh = fopen($filename, 'rb');

		$username = $this->getSetting($context->getId(), 'username');
		$password = $this->getSetting($context->getId(), 'password');
		$folderId = $this->getSetting($context->getId(), 'folderId');
		$folderId = ltrim($folderId, '/');
		$folderId = rtrim($folderId, '/');

		curl_setopt($curlCh, CURLOPT_URL, 'sftp://@hotfolder.dnb.de/'.$folderId.'/'.basename($filename));
		curl_setopt($curlCh, CURLOPT_PORT, 22122);
		curl_setopt($curlCh, CURLOPT_USERPWD, "$username:$password");
		curl_setopt($curlCh, CURLOPT_INFILESIZE, filesize($filename));
		curl_setopt($curlCh, CURLOPT_INFILE, $fh);

		$response = curl_exec($curlCh);
		$curlError = curl_error($curlCh);
		if ($curlError) {
			// error occured
			$param = __('plugins.importexport.dnb.deposit.error.fileUploadFailed.param', array('package' => basename($filename), 'articleId' => $object->getSubmissionId(), 'error' => $curlError));
			$errors = array('plugins.importexport.dnb.deposit.error.fileUploadFailed', $param);
		}
		curl_close($curlCh);
		fclose($fh);

		if (!empty($errors)) return $errors;
		return true;
	}

	/**
	 * @copydoc PubObjectsExportPlugin::executeExportAction()
	 */
	function executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation = null) {
		$journal = $request->getContext();
		$path = array('plugin', $this->getName());

		if ($request->getUserVar(EXPORT_ACTION_EXPORT) ||
			$request->getUserVar(EXPORT_ACTION_DEPOSIT)) {

			assert($filter != null);

			// The tar tool for packaging is needed. Check this
			// early on to avoid unnecessary export processing.
			$result = $this->checkForTar();
			if (is_array($result)) {
				$this->errorNotification($request, $result);
				// redirect back to the right tab
				$request->redirect(null, null, null, $path, null, $tab);
			}

			// Get the journal target export directory.
			// The data will be exported in this structure:
			// dnb/<journalId>-<dateTime>/
			$result = $this->getExportPath($journal->getId());
			if (is_array($result)) {
				$this->errorNotification($request, $result);
				// redirect back to the right tab
				$request->redirect(null, null, null, $path, null, $tab);
			}
			$journalExportPath = $result;

			$errors = $exportFilesNames = array();
			$articleDao = DAORegistry::getDAO('ArticleDAO');
			$genreDao = DAORegistry::getDAO('GenreDAO');
			$fileManager = new FileManager();

			// For each selected article
			foreach ($objects as $object) {
				$issue = null;
				$galleys = array();
				// Get issue and galleys, and check if the article can be exported
				if (!$this->canBeExported($object, $issue, $galleys)) {
					$errors[] = array('plugins.importexport.dnb.export.error.articleCannotBeExported', $object->getId());
					// continue with other articles
					continue;
				}

				$fullyDeposited = true;
				$articleId = $object->getId();
				foreach ($galleys as $galley) {
					// check if it is a full text
					$galleyFile = $galley->getFile();
					$genre = $genreDao->getById($galleyFile->getGenreId());
					// if it is not a full text, continue
					if ($genre->getCategory() != 1 || $genre->getSupplementary() || $genre->getDependent()) continue;

					$exportFile = '';
					// Get the TAR package for the galley
					$result = $this->getGalleyPackage($galley, $filter, $noValidation, $journal, $journalExportPath, $exportFile);
					// If errors occured, remove all created directories and return the errors
					if (is_array($result)) {
						$fileManager->rmtree($journalExportPath);
						$this->errorNotification($request, $result);
						// redirect back to the right tab
						$request->redirect(null, null, null, $path, null, $tab);
					}

					if ($request->getUserVar(EXPORT_ACTION_EXPORT)) {
						// Add the galley package to the list of all exported files
						$exportFilesNames[] = $exportFile;
					} elseif ($request->getUserVar(EXPORT_ACTION_DEPOSIT)) {
						// Deposit the galley
						$result = $this->depositXML($galley, $journal, $exportFile);
						if (is_array($result)) {
							// If error occured add it to the list of errors
							$errors[] = $result;
							$fullyDeposited = false;
						}
					}
				}
				if ($fullyDeposited) {
					// Update article status
					$articleDao->updateSetting($articleId, $this->getDepositStatusSettingName(), DNB_STATUS_DEPOSITED, 'string');
				}
			}

			if ($request->getUserVar(EXPORT_ACTION_EXPORT)) {
				// If there is more than one export package, package them all in a single .tar.gz
				assert(count($exportFilesNames) >= 1);
				if (count($exportFilesNames) > 1) {
					$finalExportFileName = $journalExportPath . $this->getPluginSettingsPrefix() . '-export.tar.gz';
					$this->tarFiles($journalExportPath, $finalExportFileName, $exportFilesNames, true);
				} else {
					$finalExportFileName = reset($exportFilesNames);
				}
				// Stream the results to the browser
				$fileManager->downloadFile($finalExportFileName);
				$fileManager->rmtree($journalExportPath);

			} elseif ($request->getUserVar(EXPORT_ACTION_DEPOSIT)) {
				if (!empty($errors)) {
					// If there were some deposit errors, display them to the user
					$this->errorNotification($request, $errors);
				} else {
					// Provide the user with some visual feedback that deposit was successful
					$this->_sendNotification(
						$request->getUser(),
						$this->getDepositSuccessNotificationMessageKey(),
						NOTIFICATION_TYPE_SUCCESS
					);
				}
				// Remove the generated directories
				$fileManager->rmtree($journalExportPath);
				// redirect back to the right tab
				$request->redirect(null, null, null, $path, null, $tab);
			}
		} else {
			return parent::executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation);
		}
	}

	/**
	 * Generate TAR package for a galley.
	 *
	 * @param $galley ArticleGalley
	 * @param $filter string Filter to use
	 * @param $noValidation boolean If set to true no XML validation will be done
	 * @param $journal Journal
	 * @param $journalExportPath string Directory path where to put all export files
	 * @param $exportPackageName string Just to return the exported TAR package
	 *
	 * @return boolean|array True for success or an array of error messages.
	 */
	function getGalleyPackage($galley, $filter, $noValidation, $journal, $journalExportPath, &$exportPackageName) {
		// Get the final target export directory.
		// The data will be exported in this structure:
		// dnb/<journalId>-<dateTime>/<journalId>-<articleId>-<galleyId>/
		$exportContentDir = $journal->getId() . '-' . $galley->getSubmissionId() . '-' . $galley->getId();
		$result = $this->getExportPath($journal->getId(), $journalExportPath, $exportContentDir);
		if (is_array($result)) return $result;
		$exportPath = $result;

		// Export the galley metadata XML.
		$metadataXML = $this->exportXML($galley, $filter, $journal, $noValidation);

		// Write the metadata XML to the file.
		$metadataFile = $exportPath . 'catalogue_md.xml';
		$fileManager = new FileManager();
		$fileManager->writeFile($metadataFile, $metadataXML);
		$fileManager->setMode($metadataFile, FILE_MODE_MASK);

		// Copy galley file.
		$result = $this->copyGalleyFile($galley, $exportPath);
		if (is_array($result)) return $result;

		// TAR the metadata and file.
		// The package file name will be then <journalId>-<articleId>-<galleyId>.tar
		$exportPackageName = $journalExportPath . $exportContentDir . '.tar';
		$this->tarFiles($exportPath, $exportPackageName);

		return true;
	}

	/**
	 * Copy galley file to the export content path, that will be packed.
	 *
	 * @param $galley ArticleGalley
	 * @param $exportPath string The final directory path, containing data to be packed
	 *
	 * @return string|array The export directory name or an array with
	 *  errors if something went wrong.
	 */
	function copyGalleyFile($galley, $exportPath) {
		$submissionFile = $galley->getFile();
		$sourceGalleyFilePath = $submissionFile->getFilePath();
		$targetGalleyFilePath = $exportPath . '/' . 'content'  . '/' . $submissionFile->getServerFileName();
		if (!file_exists($sourceGalleyFilePath)) {
			$errors = array(
				array('plugins.importexport.dnb.export.error.galleyFileNotFound', $sourceGalleyFilePath)
			);
			return $errors;
		}
		$fileManager = new FileManager();
		if (!$fileManager->copyFile($sourceGalleyFilePath, $targetGalleyFilePath)) {
			$errors = array(
				array('plugins.importexport.dnb.export.error.galleyFileNoCopy')
			);
			return $errors;
		}
		return realpath($targetGalleyFilePath);
	}

	/**
	 * Return the plugin export directory.
	 * The data will be exported in this structure:
	 * dnb/<journalId>-<dateTime>/<articleId>-<galleyId>/
	 *
	 * This will create the directory if it doesn't exist yet.
	 *
	 * @param $journalId integer
	 * @param $currentExportPath string (optional) The base path, the content directory should be added to
	 * @param $exportContentDir string (optional) The final directory containing data to be packed
	 *
	 * @return string|array The export directory name or an array with
	 *  errors if something went wrong.
	 */
	function getExportPath($journalId, $currentExportPath = null, $exportContentDir = null) {
		if (!$currentExportPath) {
			$exportPath = Config::getVar('files', 'files_dir') . '/' . $this->getPluginSettingsPrefix() . '/' . $journalId . '-' . date('Ymd-His');
		} else {
			$exportPath = $currentExportPath;
		}
		if ($exportContentDir) $exportPath .= '/' .$exportContentDir;
		if (!file_exists($exportPath)) {
			$fileManager = new FileManager();
			$fileManager->mkdirtree($exportPath);
		}
		if (!is_writable($exportPath)) {
			$errors = array(
				array('plugins.importexport.dnb.export.error.outputFileNotWritable', $exportPath)
			);
			return $errors;
		}
		return realpath($exportPath) . '/';
	}

	/**
	 * The selected article can be exported if the issue is published and
	 * article contains either a PDF or an EPUB full text galley.
	 * @param $article PublishedArticle
	 * @param $issue Issue Just to return the issue
	 * @param $galleys array Filtered (i.e. PDF and EPUB) article full text galleys
	 * @return boolean
	 */
	function canBeExported($article, &$issue = null, &$galleys = array()) {
		$cache = $this->getCache();
		if (!$cache->isCached('articles', $article->getId())) {
			$cache->add($article, null);
		}
		$issueId = $article->getIssueId();
		if ($cache->isCached('issues', $issueId)) {
			$issue = $cache->get('issues', $issueId);
		} else {
			$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
			$issue = $issueDao->getById($issueId, $article->getContextId());
			if ($issue) $cache->add($issue, null);
		}
		assert(is_a($issue, 'Issue'));
		if (!$issue->getPublished()) return false;
		// get all galleys
		$galleys = $article->getGalleys();
		// filter PDF and EPUB full text galleys -- DNB concerns only PDF and EPUB formats
		$filteredGalleys = array_filter($galleys, array($this, 'filterGalleys'));
		$galleys = $filteredGalleys;
		return (count($filteredGalleys) > 0);
	}

	/**
	 * Check if a galley is a full text as well as PDF or an EPUB file.
	 * @param $galley ArticleGalley
	 * @return boolean
	 */
	function filterGalleys($galley) {
		// check if it is a full text
		$genreDao = DAORegistry::getDAO('GenreDAO');
		$galleyFile = $galley->getFile();
		$genre = $genreDao->getById($galleyFile->getGenreId());
		// if it is not a full text, continue
		if ($genre->getCategory() != 1 || $genre->getSupplementary() || $genre->getDependent()) {
			return false;
		}
		return $galley->isPdfGalley() ||  $galley->getFileType() == 'application/epub+zip';
	}

	/**
	 * Test whether the tar binary is available.
	 * @return boolean|array True if available otherwise
	 *  an array with an error message.
	 */
	function checkForTar() {
		$tarBinary = Config::getVar('cli', 'tar');
		if (empty($tarBinary) || !is_executable($tarBinary)) {
			$result = array(
				array('manager.plugins.tarCommandNotFound')
			);
		} else {
			$result = true;
		}
		$this->_checkedForTar = true;
		return $result;
	}

	/**
	 * Create a tar archive.
	 * @param $targetPath string
	 * @param $targetFile string
	 * @param $sourceFiles array (optional) If null,
	 *  everything in the targeth path is considered (*).
	 * @param $gzip boolean (optional) If TAR file should be gzipped
	 */
	function tarFiles($targetPath, $targetFile, $sourceFiles = null, $gzip = false) {
		assert($this->_checkedForTar);

		$tarCommand = '';
		// Change directory to the target path, to be able to use
		// relative paths i.e. only file names and *
		$tarCommand .= 'cd ' . escapeshellarg($targetPath) . ' && ';

		// Should the result file be GZip compressed.
		$tarOptions = $gzip ? ' -czf ' : ' -cf ';
		// Construct the tar command: path to tar, options, target archive file name
		$tarCommand .= Config::getVar('cli', 'tar') . $tarOptions . escapeshellarg($targetFile);

		// Do not reveal our webserver user by forcing root as owner.
		$tarCommand .= ' --owner 0 --group 0 --';

		if (!$sourceFiles) {
			// Add everything
			$tarCommand .= ' *';
		} else {
			// Add each file individually so that other files in the directory
			// will not be included.
			foreach($sourceFiles as $sourceFile) {
				assert(dirname($sourceFile) . '/' === $targetPath);
				if (dirname($sourceFile) . '/' !== $targetPath) continue;
				$tarCommand .= ' ' . escapeshellarg(basename($sourceFile));
			}
		}

		// Execute the command.
		exec($tarCommand);
	}

	/**
	 * Check if plugin settings are missing.
	 * @param $journal Journal
	 * @return boolean
	 */
	function checkPluginSettings($journal) {
		$oaJournal =  $journal->getSetting('publishingMode') == PUBLISHING_MODE_OPEN &&
		$journal->getSetting('restrictSiteAccess') != 1 &&
		$journal->getSetting('restrictArticleAccess') != 1;
		// if journal is not open access, the archive access setting has to be set
		return $oaJournal || $this->getSetting($journal->getId(), 'archiveAccess');
	}

	/**
	 * Display error notification.
	 * @param $request Request
	 * @param $errors array
	 */
	function errorNotification($request, $errors) {
		foreach($errors as $error) {
			assert(is_array($error) && count($error) >= 1);
			$this->_sendNotification(
				$request->getUser(),
				$error[0],
				NOTIFICATION_TYPE_ERROR,
				(isset($error[1]) ? $error[1] : null)
			);
		}
	}

}

?>
