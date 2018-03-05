<?php
/**
 * @file plugins/importexport/bepress/BepressImportPlugin.inc.php
 *
 * Copyright (c) 2017 Simon Fraser University
 * Copyright (c) 2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class BepressImportPlugin
 * @ingroup plugins_importexport_bepress
 *
 * @brief Bepress XML import plugin
 */

import('lib.pkp.classes.plugins.ImportExportPlugin');

class BepressImportPlugin extends ImportExportPlugin {
	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();
		return $success;
	}

	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 * @return String name of plugin
	 */
	function getName() {
		return 'BepressImportPlugin';
	}

	function getDisplayName() {
		return __('plugins.importexport.bepress.displayName');
	}

	function getDescription() {
		return __('plugins.importexport.bepress.description');
	}

	/**
	 * Execute import tasks using the command-line interface.
	 * @param $args array plugin options
	 */
	function executeCLI($scriptName, &$args) {

		if (sizeof($args) != 5) {
			$this->usage($scriptName);
			exit();
		}

		$journalPath = array_shift($args);
		$username = array_shift($args);
		$editorUsername = array_shift($args);
		$defaultEmail = array_shift($args);
		$directoryName = rtrim(array_shift($args), '/');

		if (!$journalPath || !$username || !$editorUsername || !$directoryName || !$defaultEmail) {
			$this->usage($scriptName);
			exit();
		}

		$journalDao = DAORegistry::getDAO('JournalDAO');
		$journal = $journalDao->getByPath($journalPath);
		if (!$journal) {
			echo __('plugins.importexport.bepress.unknownJournal', array('journal' => $journalPath)) . "\n";
			exit();
		}

		$userDao = DAORegistry::getDAO('UserDAO');
		$user = $userDao->getByUsername($username);
		if (!$user) {
			echo __('plugins.importexport.bepress.unknownUser', array('username' => $username)) . "\n";
			exit();
		}

		$editor = $userDao->getByUsername($editorUsername);
		if (!$editor) {
			echo __('plugins.importexport.bepress.unknownUser', array('username' => $editorName)) . "\n";
			exit();
		}

		if (!file_exists($directoryName) && is_dir($directoryName) ) {
			echo __('plugins.importexport.bepress.directoryDoesNotExist', array('directory' => $directoryName)) . "\n";
			exit();
		}

		if (!filter_var($defaultEmail, FILTER_VALIDATE_EMAIL)){
			echo __('plugins.importexport.bepress.unknownEmail', array('email' => $defaultEmail)). "\n";
			exit();
		}

		$this->import('BepressImportDom');
		echo __('plugins.importexport.bepress.importStart');

		// Import volumes from oldest to newest
		$volumeHandle = opendir($directoryName);
		while ($importVolumes[] = readdir($volumeHandle));
		sort($importVolumes, SORT_NATURAL);
		closedir($volumeHandle);

		foreach ($importVolumes as $volumeName){
			$volumePath = $directoryName . '/' . $volumeName;
			if (!is_dir($volumePath) || preg_match('/^\./', $volumeName) || !$volumeName) continue;

			// Import issues from oldest to newest
			$importIssues = array();
			$issueHandle = opendir($volumePath);
			while ($importIssues[] = readdir($issueHandle));
			sort($importIssues, SORT_NATURAL);
			closedir($issueHandle);

			$allIssueIds = array();
			$curIssueId = 0;

			foreach ($importIssues as $issueName){
				$issuePath = $volumePath . '/' . $issueName;
				if (!is_dir($issuePath) || preg_match('/^\./', $issueName) || !$issueName) continue;

				// Import articles from oldest to newest
				$importArticles = array();
				$articleHandle = opendir($issuePath);
				while ($importArticles[] = readdir($articleHandle));
				sort($importArticles, SORT_NATURAL);
				closedir($articleHandle);

				$currSectionId = 0;
				$allSectionIds = array();

				foreach ($importArticles as $entry) {
					$articlePath = $issuePath . '/' . $entry;
					if (!is_dir($articlePath) || preg_match('/^\./', $entry) || !$entry) continue;

					// Process all article files
					$articleFileHandle = opendir($articlePath);
					$importFiles = array();
					while ($importFiles[] = readdir($articleFileHandle));
					sort($importFiles, SORT_NATURAL);
					closedir($articleFileHandle);

					$xmlArticleFile = null;
					$pdfArticleFile = null;
					foreach($importFiles as $importFile){
						if (preg_match('/metadata\.xml$/', $importFile) && !$xmlArticleFile){
							$xmlArticleFile = $articlePath . '/' . $importFile;
						} elseif (preg_match('/fulltext\.pdf$/', $importFile) && !$pdfArticleFile){
							$pdfArticleFile = $articlePath . '/' . $importFile;
						}
					}

					if (!$xmlArticleFile || !$pdfArticleFile) continue;

					if (is_file($xmlArticleFile)) {
						$xmlArticle = $this->getDocument($xmlArticleFile);
						if ($xmlArticle) {
							$number = null;
							preg_match_all('/\d+/',basename(dirname(dirname($xmlArticleFile))), $number);
							$number = array_shift(array_shift($number));

							$volume = null;
							preg_match_all('/\d+/',basename(dirname(dirname(dirname($xmlArticleFile)))), $volume);
							$volume = array_shift(array_shift($volume));
							$importDom = new BepressImportDom(
									$journal,
									$user,
									$editor,
									$xmlArticle,
									$pdfArticleFile,
									$volume,
									$number,
									$defaultEmail
							);
							$returner = $importDom->importArticle();
							unset($importDom);

							if ($returner && is_array($returner)) {
								$issue = $returner['issue'];
								$section = $returner['section'];
								$article = $returner['article'];

								$issueId = $issue->getId();
								$sectionId = $section->getId();

								if ($curIssueId != $issueId) {
									$allIssueIds[] = $issueId;
									$curIssueId = $issueId;
									$issueTitle = $issue->getIssueIdentification();
									echo __('plugins.importexport.bepress.issueImport', array('title' => $issueTitle)) . "\n\n";
								}
								if ($currSectionId != $sectionId){
									$currSectionId = $sectionId;
									$sectionTitle = $section->getLocalizedTitle();
									echo __('plugins.importexport.bepress.sectionImport', array('title' => $sectionTitle)) . "\n\n";
								}

								if (!in_array($sectionId, $allSectionIds)) {
									$allSectionIds[] = $sectionId;
								}

								$articleTitle = $article->getLocalizedTitle();
								echo __('plugins.importexport.bepress.articleImported', array('title' => $articleTitle)) . "\n\n";
							}
						}
					}
				}
			}

			// Add default custom section ordering for TOC
			$sectionDao =& DAORegistry::getDAO('SectionDAO');
			$numSections = 0;

			// Add each section in order for articles
			foreach ($allSectionIds as $curSectionId) {
				$sectionDao->insertCustomSectionOrder($issueId, $curSectionId, ++$numSections);
			}
		}

		// Setup default custom issue order
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		$issueDao->setDefaultCustomIssueOrders($journal->getId());

		// Set latest imported issue as current issue
		if (!empty($allIssueIds)) {
			$lastIssueId = array_pop($allIssueIds);
			$lastIssue =& $issueDao->getById($lastIssueId);
			$lastIssue->setCurrent(1);
			$issueDao->updateObject($lastIssue);
		}

		echo __('plugins.importexport.bepress.importEnd');
		exit();
	}

	/**
	 * Display the command-line usage information
	 */
	function usage($scriptName) {
		echo __('plugins.importexport.bepress.cliUsage', array(
				'scriptName' => $scriptName,
				'pluginName' => $this->getName()
		)) . "\n";
	}

	function &getDocument($fileName) {
		$parser = new XMLParser();
		$returner =& $parser->parse($fileName);
		return $returner;
	}
}
?>