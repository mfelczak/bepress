<?php
/**
 * @file plugins/importexport/bepress/BepressImportDom.inc.php
 *
 * Copyright (c) 2017 Simon Fraser University
 * Copyright (c) 2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class Bepress
 * @ingroup plugins_importexport_bepress
 *
 * @brief Bepress XML import DOM functions
 */
import('lib.pkp.classes.xml.XMLCustomWriter');
import('lib.pkp.classes.file.SubmissionFileManager');
import('classes.issue.Issue');
import('classes.journal.Section');
import('classes.article.Article');
import('classes.article.Author');
import('classes.search.ArticleSearchIndex');

class BepressImportDom {
	var $_journal = null;
	var $_user = null;
	var $_editor = null;
	var $_editorGroupId = null;
	var $_xmlArticle = null;
	var $_articleNode = null;
	var $_articleTitle = null;
	var $_article = null;
	var $_section = null;
	var $_issue = null;
	var $_primaryLocale = null;
	var $_pdfPath = null;
	var $_volume = null;
	var $_number = null;
	var $_defaultEmail = null;
	var $_dependentItems = array();
	var $_errors = array();

	/**
	 * Constructor.
	 */
	function __construct(&$journal, &$user, &$editor, &$xmlArticle, $pdfPath, $volume, $number, $defaultEmail) {
		$this->_journal = $journal;
		$this->_user = $user;
		$this->_editor = $editor;
		$this->_xmlArticle = $xmlArticle;
		$this->_pdfPath = $pdfPath;
		$this->_volume = $volume;
		$this->_number = $number;
		$this->_defaultEmail = $defaultEmail;
	}

	/**
	 * Import an article along with parent section and issue
	 * @return array Imported objects with the following keys: 'issue', 'section', 'article'
	 */
	function importArticle() {
		if (!$this->_journal || !$this->_user || !$this->_editor || !$this->_xmlArticle || !$this->_pdfPath || !$this->_volume || !$this->_number || !$this->_defaultEmail) {
			return null;
		}

		$this->_articleNode = $this->_xmlArticle->getChildByName('document');
		if (!$this->_articleNode) return null;

		$this->_getArticleTitle();
		$this->_primaryLocale = $this->_journal->getPrimaryLocale();

		$result = $this->_handleArticleNode();

		if (!$result) {
			$this->_cleanupFailure();
		}

		return $result;
	}

	/**
	 * Handle the Article node, construct article and related objects from XML.
	 * @return array Imported objects with the following keys: 'issue', 'section', 'article'
	 */
	function _handleArticleNode() {
		// Process issue first
		$this->_handleIssue();

		// Ensure we have an issue
		if (!$this->_issue) {
			$this->_errors[] = array('plugins.importexport.bepress.import.error.missingIssue', array('title' => $this->_articleTitle));
			return null;
		}

		// Process article section
		$this->_handleSection();

		// Ensure we have a section
		if (!$this->_section) {
			$this->_errors[] = array('plugins.importexport.bepress.import.error.missingSection', array('title' => $this->_articleTitle));
			return null;
		}

		// We have an issue and section, we can now process the article
		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
		$articleDao = DAORegistry::getDAO('ArticleDAO');

		$this->_article = new Article();
		$this->_article->setLocale($this->_primaryLocale);
		$this->_article->setLanguage('en');
		$this->_article->setJournalId($this->_journal->getId());
		$this->_article->setSectionId($this->_section->getId());
		$this->_article->setStatus(STATUS_PUBLISHED);
		$this->_article->setStageId(WORKFLOW_STAGE_ID_PRODUCTION);
		$this->_article->setSubmissionProgress(0);
		$this->_article->setTitle($this->_articleTitle, $this->_primaryLocale);

		// Get article abstract if it exists, possibly in multiple locales
		$abstractText = '';
		$abstractNode = $this->_articleNode->getChildByName('abstract');
		if ($abstractNode) {
			$abstractLocale = $abstractNode->getAttribute('locale');
			if (!$abstractLocale) $abstractLocale = $this->_primaryLocale;
			$abstractText = $abstractNode->getValue();
			Request::cleanUserVar($abstractText);
			$abstractText = html_entity_decode(trim($abstractText), ENT_HTML5);
			if ($abstractText) $this->_article->setAbstract($abstractText, $abstractLocale);
		} else {
			$abstractsNode = $this->_articleNode->getChildByName('abstracts');
			if ($abstractsNode){
				for ($i = 0; $abstractNode = $abstractsNode->getChildByName('abstract', $i); $i++){
					$abstractLocale = $abstractNode->getAttribute('locale');
					if (!$abstractLocale) $abstractLocale = $this->_primaryLocale;
					$abstractText = $abstractNode->getValue();
					Request::cleanUserVar($abstractText);
					$abstractText = html_entity_decode(trim($abstractText), ENT_HTML5);
					if ($abstractText) $this->_article->setAbstract($abstractText, $abstractLocale);
				}
			}
		}

		// Retrieve license and date published fields if available
		$fieldsNode = $this->_articleNode->getChildByName('fields');
		$licenseUrl = null;
		$articlePublicationDate = null;
		if ($fieldsNode){
			for ($i = 0; $fieldNode = $fieldsNode->getChildByName('field', $i); $i++){
				$fieldName = $fieldNode->getAttribute('name');
				$fieldValueNode = $fieldNode->getChildByName('value');
				if ($fieldValueNode) {
					switch ($fieldName) {
						case 'distribution_license':
							$licenseUrl = $fieldValueNode->getValue();
							Request::cleanUserVar($licenseUrl);
							$licenseUrl = filter_var(trim($licenseUrl), FILTER_VALIDATE_URL);
							continue;
						case 'publication_date':
							$articlePublicationDate = $fieldValueNode->getValue();
							continue;
					}
				}
			}
		}

		$checkDate = date_parse($articlePublicationDate);
		if (!$checkDate || !checkdate($checkDate['month'], $checkDate['day'], $checkDate['year'])) {
			$articlePublicationDate = $this->_issue->getDatePublished();
		} else {
			$articlePublicationDate = date("Y-m-d H:i:s", strtotime($articlePublicationDate));
		}

		// Retrieve submission date
		$submissionDateNode = $this->_articleNode->getChildByName('submission-date');
		if ($submissionDateNode) {
			$submissionDate = $submissionDateNode->getValue();
			$checkDate = date_parse($submissionDate);
			if (!$checkDate || !checkdate($checkDate['month'], $checkDate['day'], $checkDate['year'])) {
				$submissionDate = $articlePublicationDate;
			} else {
				$submissionDate = date("Y-m-d H:i:s", strtotime($submissionDate));
			}
		} else {
			$submissionDate = $articlePublicationDate;
		}

		$this->_article->setDateSubmitted($submissionDate);
		$this->_article->setDateStatusModified($articlePublicationDate);

		// Add article
		$articleDao->insertObject($this->_article);
		$this->_dependentItems[] = 'article';

		// Process authors and assign to article
		$this->_processAuthors();

		// Process article keywords
		$submissionKeywordDAO = DAORegistry::getDAO('SubmissionKeywordDAO');
		$keywordsNode = $this->_articleNode->getChildByName('keywords');
		$keywords[$this->_primaryLocale] = array();
		if ($keywordsNode){
			for ($i = 0; $keywordNode = $keywordsNode->getChildByName('keyword', $i); $i++){
				$keywordText = $keywordNode->getValue();
				// Check if all keywords are in single element separated by ;
				$curKeywords = explode(';', $keywordText);
				foreach ($curKeywords as $curKeyword) {
					$keywords[$this->_primaryLocale][] = $curKeyword;
				}
			}
		}
		$submissionKeywordDAO->insertKeywords($keywords, $this->_article->getId());

		// Process article subjects
		$submissionSubjectDAO = DAORegistry::getDAO('SubmissionSubjectDAO');
		$subjectsNode = $this->_articleNode->getChildByName('subject-areas');
		$subjects[$this->_primaryLocale] = array();
		if ($subjectsNode){
			for ($i = 0; $subjectNode = $subjectsNode->getChildByName('subject-area', $i); $i++){
				$subjectText = $subjectNode->getValue();
				// Check if all subjects are in single element separated by ;
				$curSubjects = explode(';', $subjectText);
				foreach ($curSubjects as $curSubject) {
					$subjects[$this->_primaryLocale][] = $curSubject;
				}
			}
		}
		$submissionSubjectDAO->insertSubjects($subjects, $this->_article->getId());

		// Process article disciplines
		$submissionDisciplineDAO = DAORegistry::getDAO('SubmissionDisciplineDAO');
		$disciplinesNode = $this->_articleNode->getChildByName('disciplines');
		$disciplines[$this->_primaryLocale] = array();
		if ($disciplinesNode){
			for ($i = 0; $disciplineNode = $disciplinesNode->getChildByName('discipline', $i); $i++){
				$disciplineText = $disciplineNode->getValue();
				// Check if all disciplines are in single element separated by ;
				$curDisciplines = explode(';', $disciplineText);
				foreach ($curDisciplines as $curDiscipline) {
					$disciplines[$this->_primaryLocale][] = $curDiscipline;
				}
			}
		}
		$submissionDisciplineDAO->insertDisciplines($disciplines, $this->_article->getId());

		// Assign editor as participant in production stage
		$userGroupId = null;
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$userGroupIds = $userGroupDao->getUserGroupIdsByRoleId(ROLE_ID_MANAGER, $this->_journal->getId());
		foreach ($userGroupIds as $editorGroupId) {
			if ($userGroupDao->userGroupAssignedToStage($editorGroupId, $this->_article->getStageId())) break;
		}
		if ($editorGroupId) {
			$this->_editorGroupId = $editorGroupId;
			$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
			$stageAssignment = $stageAssignmentDao->build($this->_article->getId(), $editorGroupId, $this->_editor->getId());
		} else {
			$this->_errors[] = array('plugins.importexport.bepress.import.error.missingEditorGroupId', array());
			return null;
		}

		// Insert published article entry
		$publishedArticle = new PublishedArticle();
		$publishedArticle->setId($this->_article->getId());
		$publishedArticle->setSectionId($this->_article->getSectionId());
		$publishedArticle->setIssueId($this->_issue->getId());
		$publishedArticle->setDatePublished($articlePublicationDate);
		$publishedArticle->setAccessStatus(ARTICLE_ACCESS_OPEN);
		$publishedArticle->setSequence($this->_article->getId());
		$publishedArticleDao->insertObject($publishedArticle);

		// Set copyright year and holder and license permissions
		$copyrightYear = date("Y", strtotime($articlePublicationDate));
		$copyrightHolder = $this->_article->getAuthorString();
		if ($copyrightHolder) $this->_article->setCopyrightHolder($copyrightHolder, $this->_primaryLocale);
		if ($copyrightYear) $this->_article->setCopyrightYear($copyrightYear);
		if ($licenseUrl) $this->_article->setLicenseURL($licenseUrl);

		// Use journal defaults for missing copyright/license info
		$this->_article->initializePermissions();

		// Update copyright/license info
		$articleDao->updateLocaleFields($this->_article);

		// Handle PDF galleys
		$this->_handlePDFGalleyNode();

		// Index the article
		$articleSearchIndex = new ArticleSearchIndex();
		$articleSearchIndex->articleMetadataChanged($this->_article);
		$articleSearchIndex->submissionFilesChanged($this->_article);
		$articleSearchIndex->articleChangesFinished();

		$returner = array(
				'issue' => $this->_issue,
				'section' => $this->_section,
				'article' => $this->_article
		);
		return $returner;
	}

	/**
	 * Handle issue data and create new issue if it doesn't already exist
	 */
	function _handleIssue() {
		// Ensure we have a volume and issue number
		if (!$this->_volume || !$this->_number) {
			$this->_errors[] = array('plugins.importexport.bepress.import.error.missingVolumeNumber', array('title' => $this->_articleTitle));
			return;
		}

		// If this issue already exists, return it
		$issueDao = DAORegistry::getDAO('IssueDAO');
		$issues = $issueDao->getPublishedIssuesByNumber($this->_journal->getId(), $this->_volume, $this->_number);
		if (!$issues->eof()) {
			$this->_issue = $issues->next();
			return;
		}

		// Determine issue publication date based on article publication date
		$pubDateNode = $this->_articleNode->getChildByName('publication-date');
		$date = date_parse($pubDateNode->getValue());
		$year = (int) $date['year'];
		$month = (int) $date['month'];
		$day = $date['day'];

		// Ensure we have a year
		if (!$year || !is_numeric($year)) {
			$errors[] = array('plugins.importexport.bepress.import.error.missingPubDate', array('title' => $this->_articleTitle));
			return;
		}

		if (!$month || !is_numeric($month)) {
			$errors[] = array('plugins.importexport.bepress.import.error.missingPubDate', array('title' => $this->_articleTitle));
			return;
		}

		// Ensure we have a day
		if (!$day) $day = "1";
		if (!$month) $month = "1";

		// Ensure two digit months and days for issue publication date
		if (preg_match('/^\d$/', $month)) { $month = '0' . $month; }
		if (preg_match('/^\d$/', $day)) { $day = '0' . $day; }
		$publishedDate = $year . '-' . $month . '-' . $day;

		// Create new issue
		$this->_issue = new Issue();
		$this->_issue->setJournalId($this->_journal->getId());
		$this->_issue->setVolume((int)$this->_volume);
		$this->_issue->setNumber((int)$this->_number);
		$this->_issue->setYear((int)$year);
		$this->_issue->setPublished(1);
		$this->_issue->setCurrent(0);
		$this->_issue->setDatePublished($publishedDate);
		$this->_issue->stampModified();
		$this->_issue->setAccessStatus(ISSUE_ACCESS_OPEN);
		$this->_issue->setShowVolume(1);
		$this->_issue->setShowNumber(1);
		$this->_issue->setShowYear(1);
		$this->_issue->setShowTitle(0);
		$issueDao->insertObject($this->_issue);

		if (!$this->_issue->getId()) {
			unset($this->_issue);
			return;
		} else {
			$this->_dependentItems[] = 'issue';
		}
	}

	/**
	 * Handle section data and create new section if it doesn't already exist
	 */
	function _handleSection() {
		//Get section name from either the document-type or type tag
		$sectionName = null;
		$documentType = $this->_articleNode ? $this->_articleNode->getChildValue('document-type') : null;
		$type = $this->_articleNode ? $this->_articleNode->getChildValue('type') : null;

		if ($documentType){
			$sectionNameRaw = str_replace('_', ' ', $documentType);
			$sectionName = ucwords(strtolower($sectionNameRaw));
		} else if ($type){
			$sectionNameRaw = str_replace('_', ' ', $type);
			$sectionName = ucwords(strtolower($sectionNameRaw));
		}

		if ($sectionName) {
			Request::cleanUserVar($sectionName);
			$sectionName = trim($sectionName);
		} else {
			$sectionName = 'Articles';
		}

		// Ensure we have a section name
		if (!$sectionName) {
			$this->_errors[] = array('plugins.importexport.bepress.import.error.missingSection', array('title' => $this->_articleTitle));
			return;
		}

		// If this section already exists, return it
		$sectionDao = DAORegistry::getDAO('SectionDAO');
		$this->_section = $sectionDao->getByTitle($sectionName, $this->_journal->getId(), $this->_primaryLocale);
		if ($this->_section) return;

		// Otherwise, create a new section
		AppLocale::requireComponents(LOCALE_COMPONENT_APP_DEFAULT);
		$this->_section = new Section();
		$this->_section->setJournalId($this->_journal->getId());
		$this->_section->setTitle($sectionName, $this->_primaryLocale);
		$this->_section->setAbbrev(strtoupper(substr($sectionName, 0, 3)), $this->_primaryLocale);
		$this->_section->setAbstractsNotRequired(true);
		$this->_section->setMetaIndexed(true);
		$this->_section->setMetaReviewed(false);
		$this->_section->setPolicy(__('section.default.policy'), $this->_primaryLocale);
		$this->_section->setEditorRestricted(true);
		$this->_section->setHideTitle(false);
		$this->_section->setHideAuthor(false);

		$sectionDao->insertObject($this->_section);

		if (!$this->_section->getId()) {
			unset($this->_section);
			return;
		}
	}

	/**
	 * Process all article authors.
	 */
	function _processAuthors() {
		$authorDao = DAORegistry::getDAO('AuthorDAO');

		$userGroupId = null;
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$userGroupIds = $userGroupDao->getUserGroupIdsByRoleId(ROLE_ID_AUTHOR, $this->_journal->getId());
		if (!empty($userGroupIds)) $userGroupId = $userGroupIds[0];

		$contributorNode = $this->_articleNode->getChildByName('authors');
		if (!$contributorNode) {
			// No authors present, create default 'N/A' author
			$author = $this->_createEmptyAuthor($userGroupId);
			$authorDao->insertObject($author);
		} else {
			// Otherwise, parse all author names first
			for ($index=0; ($node = $contributorNode->getChildByName('author', $index)); $index++) {
				if (!$node) continue;
				$author = $this->_handleAuthorNode(
						$node,
						$index,
						$userGroupId
				);
				if ($author) $authorDao->insertObject($author);
			}
		}
	}

	/**
	 * Handle an author node (i.e. convert an author from DOM to DAO).
	 * @param $authorNode DOMElement
	 * @param $authorIndex int 0 for first author, 1 for second, ...
	 * @param $userGroupId int author user group ID
	 */
	function _handleAuthorNode(&$authorNode, $authorIndex, $userGroupId) {
		$author = new Author();

		$fname = $authorNode->getChildValue('fname');
		$lname = $authorNode->getChildValue('lname');
		$mname = $authorNode->getChildValue('mname');
		$suffix = $authorNode->getChildValue('suffix');

		$email = $authorNode->getChildValue('email');
		$affiliation = $authorNode->getChildValue('institution');

		$author->setFirstName(isset($fname)? $fname : '');
		$author->setLastName(isset($lname)? $lname : $this->_journal->getName($this->_primaryLocale));
		$author->setMiddleName((isset($mname))? $mname : '');
		$author->setSuffix(isset($suffix)? $suffix : '');
		$author->setEmail(isset($email)? $email : $this->_defaultEmail);
		$author->setAffiliation((isset($affiliation)? $affiliation : ''), $this->_primaryLocale);

		$author->setSequence($authorIndex + 1); // 1-based
		$author->setSubmissionId($this->_article->getId());
		$author->setIncludeInBrowse(true);
		$author->setPrimaryContact($authorIndex == 0 ? 1:0);

		if ($userGroupId) $author->setUserGroupId($userGroupId);

		return $author;
	}

	/**
	 * Add 'empty' author for articles with no author information
	 * @param $userGroupId int author user group ID
	 * @return Author
	 */
	function _createEmptyAuthor($userGroupId) {
		$author = new Author();
		$author->setFirstName('');
		$author->setLastName($this->_journal->getName($this->_primaryLocale));
		$author->setSequence(1);
		$author->setSubmissionId($this->_article->getId());
		$author->setEmail($this->_defaultEmail);
		$author->setPrimaryContact(1);
		$author->setIncludeInBrowse(true);

		if ($userGroupId) $author->setUserGroupId($userGroupId);

		return $author;
	}

	/**
	 * Import a PDF Galley.
	 */
	function _handlePDFGalleyNode() {
		$pdfFilename = basename($this->_pdfPath);

		// Create a representation of the article (i.e. a galley)
		$representationDao = Application::getRepresentationDAO();
		$representation = $representationDao->newDataObject();
		$representation->setSubmissionId($this->_article->getId());
		$representation->setName($pdfFilename, $this->_primaryLocale);
		$representation->setSequence(1);
		$representation->setLabel('PDF');
		$representation->setLocale($this->_primaryLocale);
		$representationDao->insertObject($representation);

		// Add the PDF file and link representation with submission file
		$genreDao = DAORegistry::getDAO('GenreDAO');
		$genre = $genreDao->getByKey('SUBMISSION', $this->_journal->getId());

		$submissionFileManager = new SubmissionFileManager($this->_journal->getId(), $this->_article->getId());

		$submissionFile = $submissionFileManager->copySubmissionFile(
			$this->_pdfPath,
			SUBMISSION_FILE_PROOF,
			$this->_editor->getId(),
			null,
			$genre->getId(),
			ASSOC_TYPE_REPRESENTATION,
			$representation->getId()
		);
		$representation->setFileId($submissionFile->getFileId());
		$representationDao->updateObject($representation);
	}

	function _getArticleTitle() {
		$titleNode = $this->_articleNode->getChildByName('title');
		$title = $titleNode->getValue();
		Request::cleanUserVar($title);
		$this->_articleTitle = html_entity_decode(trim($title), ENT_HTML5);
	}

	function _cleanupFailure() {
		$issueDao = DAORegistry::getDAO('IssueDAO');
		$articleDao = DAORegistry::getDAO('ArticleDAO');

		foreach ($this->_dependentItems as $dependentItem) {
			switch ($dependentItem) {
				case 'issue':
					$issueDao->deleteIssue($this->_issue);
					break;
				case 'article':
					$articleDao->deleteArticle($this->_article);
					break;
				default:
					fatalError ('Cleanup Failure: Unimplemented type');
			}
		}

		foreach ($this->_errors as $error) {
			if (size($error) > 1) {
				echo __($error[0], $error[1]);
			} else {
				echo __($error[0]);
			}
		}
	}
}

?>