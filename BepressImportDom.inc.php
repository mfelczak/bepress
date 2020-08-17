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
import('classes.submission.Submission');
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
	var $_articleTitleLocalizedArray = null;
	var $_submission = null;
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

		$this->_primaryLocale = $this->_journal->getPrimaryLocale();
		$this->_getArticleTitle();

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

		// We have an issue and section, we can now process the article submission and publication object
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');

		// We process the submission first
		$this->_submission = $submissionDao->newDataObject();
		$this->_submission->setData('contextId', $this->_journal->getId());
		$this->_submission->stampModified();
		$this->_submission->setData('locale', $this->_primaryLocale);
		$this->_submission->setData('status', STATUS_PUBLISHED);
		$this->_submission->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
		$this->_submission->setData('submissionProgress', 0);

		// Retrieve license and date published fields if available
		$fieldsNode = $this->_articleNode->getChildByName('fields');
		$licenseUrl = null;
		$articlePublicationDate = null;
		if ($fieldsNode) {
			for ($i = 0; $fieldNode = $fieldsNode->getChildByName('field', $i); $i++) {
				$fieldName = $fieldNode->getAttribute('name');
				$fieldValueNode = $fieldNode->getChildByName('value');
				if ($fieldValueNode) {
					switch ($fieldName) {
						case 'distribution_license':
							$licenseUrl = $fieldValueNode->getValue();
							$licenseUrl = filter_var(trim($licenseUrl), FILTER_VALIDATE_URL);
							continue;
						case 'publication_date':
							$articlePublicationDate = $fieldValueNode->getValue();
							continue;
						case 'doi':
							$doiValue = $fieldValueNode->getValue();
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

		$this->_submission->setData('dateSubmitted', $submissionDate);
		$this->_submission->setData('dateLastActivity', $articlePublicationDate);

		// Add article submission
		$submissionId = $submissionDao->insertObject($this->_submission);
		$this->_submission = $submissionDao->getById($submissionId);
		$this->_dependentItems[] = 'article';

		// Create publication object and add info
		$publicationDao = DAORegistry::getDAO('PublicationDAO');

		$publication = $publicationDao->newDataObject();
		/** @var $publication PKPPublication */
		$publication->setData('submissionId', $this->_submission->getId());
		$publication->setData('locale', $this->_primaryLocale);
		$publication->setData('languages', 'en');
		$publication->setData('sectionId', $this->_section->getId());
		$publication->setData('issueId', $this->_issue->getId());
		$publication->setData('datePublished', $articlePublicationDate);
		$publication->setData('accessStatus', ARTICLE_ACCESS_OPEN);
		$publication->setData('seq', $this->_submission->getId());

		// Get article title, possibly in multiple locales
		if (!empty($this->_articleTitleLocalizedArray)) {
			foreach ($this->_articleTitleLocalizedArray as $locale => $titleList) {
				foreach ($titleList as $titleText) {
					$publication->setData('title', $titleText, $locale);
				}
			}
		} else {
			// Throw error if no titles for any locale
			$this->_errors[] = array('plugins.importexport.bepress.import.error.articleTitleMissing', array());
			return null;
		}

		$publication->stampModified();
		$publication->setData('status', STATUS_PUBLISHED);
		$publication->setData('version', 1);

		// Get article abstract if it exists, possibly in multiple locales
		$abstractLocalizedArray = $this->_getLocalizedElements($this->_articleNode, 'abstract', 'abstracts');
		if (!empty($abstractLocalizedArray)) {
			foreach ($abstractLocalizedArray as $locale => $abstractList) {
				foreach ($abstractList as $abstractText) {
					$publication->setData('abstract', $abstractText, $locale);
				}
			}
		}

		// Retrieve article pages if provided
		$firstPageNode = $this->_articleNode->getChildByName('fpage');
		if ($firstPageNode) {
			$firstPage = $firstPageNode->getValue();
			if ($firstPage) {
				$lastPageNode = $this->_articleNode->getChildByName('lpage');
				if ($lastPageNode) {
					$lastPage = $lastPageNode->getValue();
					if ($lastPage) {
						$pages = $firstPage . "-" . $lastPage;
						$publication->setData('pages', $pages);
					}
				}
			}
		}

		// Insert publication entry
		$publicationId = $publicationDao->insertObject($publication);
		$publication = $publicationDao->getById($publicationId);

		// Create association between new publication version and article submission
		$newSubmission = $submissionDao->newDataObject();
		$newSubmission->_data = array_merge($this->_submission->_data, ['currentPublicationId' => $publication->getId()]);
		$submissionDao->updateObject($newSubmission);
		$this->_submission = $submissionDao->getById($newSubmission->getId());

		// Process authors and assign to article
		$this->_processAuthors();

		// Assign editor as participant in production stage
		$userGroupId = null;
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$userGroupIds = $userGroupDao->getUserGroupIdsByRoleId(ROLE_ID_MANAGER, $this->_journal->getId());
		foreach ($userGroupIds as $editorGroupId) {
			if ($userGroupDao->userGroupAssignedToStage($editorGroupId, $this->_submission->getData('stageId'))) break;
		}
		if ($editorGroupId) {
			$this->_editorGroupId = $editorGroupId;
			$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
			$stageAssignment = $stageAssignmentDao->build($this->_submission->getId(), $editorGroupId, $this->_editor->getId());
		} else {
			$this->_errors[] = array('plugins.importexport.bepress.import.error.missingEditorGroupId', array());
			return null;
		}

		// Set DOI if provided via article-id rather than field tag
		if (!isset($doiValue)) {
			$articleIdNode = $this->_articleNode->getChildByName('article-id');
			if ($articleIdNode) {
				$pubIdType = $articleIdNode->getAttribute('pub-id-type');
				if ($pubIdType == 'doi') {
					$doiValue = $articleIdNode->getValue();
				}
			}
		}

		if (isset($doiValue)) $publicationDao->changePubId($this->_submission->getCurrentPublication()->getId(), 'doi', $doiValue);

		// Set copyright year and holder and license permissions
		$copyrightYear = date("Y", strtotime($articlePublicationDate));
		// FIXME: `Submission::getAuthorString()` deprecated in 3.2.0.0
		$copyrightHolder = $this->_submission->getAuthorString();

		// Create new temp publication to add license info
		$newPublication = $publicationDao->newDataObject();

		if ($copyrightHolder) $newPublication->setData('copyrightHolder', $copyrightHolder, $this->_primaryLocale);
		if ($copyrightYear) $newPublication->setData('copyrightYear', $copyrightYear);
		if ($licenseUrl) $newPublication->setData('licenseUrl', $licenseUrl);

		// Use journal defaults for missing copyright/license info
		if (!$newPublication->getData('copyrightHolder')) {
			$newPublication->setData('copyrightHolder', $this->_submission->_getContextLicenseFieldValue(null, PERMISSIONS_FIELD_COPYRIGHT_HOLDER, $publication));
		}
		if (!$newPublication->getData('copyrightYear') && $this->_submission->getData('status') == STATUS_PUBLISHED) {
			$newPublication->setData('copyrightYear', $this->_submission->_getContextLicenseFieldValue(null, PERMISSIONS_FIELD_COPYRIGHT_YEAR, $publication));
		}
		if (!$newPublication->getData('licenseUrl')) {
			$newPublication->setData('licenseUrl', $this->_submission->_getContextLicenseFieldValue(null, PERMISSIONS_FIELD_LICENSE_URL, $publication));
		}

		// Update copyright/license info
		$newPublication->_data = array_merge($publication->_data, $newPublication->_data);
		$publicationDao->updateObject($newPublication);
		$publication = $publicationDao->getById($newPublication->getId());

		// We process controlled vocab metadata after license to prevent their removal when updating publication object.

		// Process article keywords
		$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO'); /* @var $submissionKeywordDao SubmissionKeywordDAO */
		$keywordsLocalizedArray = $this->_getLocalizedElements($this->_articleNode, 'keyword', 'keywords');
		$keywords = array();
		if (!empty($keywordsLocalizedArray)) {
			foreach ($keywordsLocalizedArray as $locale => $keywordList) {
				$keywords[$locale] = array();
				foreach ($keywordList as $keywordText) {
					// Check if all keywords are in single element separated by ;
					$curKeywords = explode(';', $keywordText);
					foreach ($curKeywords as $curKeyword) {
						$keywords[$locale][] = $curKeyword;
					}
				}
			}
		}
		$submissionKeywordDao->insertKeywords($keywords, $this->_submission->getCurrentPublication()->getId());

		// Process article subjects
		$submissionSubjectDAO = DAORegistry::getDAO('SubmissionSubjectDAO');
		$subjectsLocalizedArray = $this->_getLocalizedElements($this->_articleNode, 'subject-area', 'subject-areas');
		$subjects = array();
		if (!empty($subjectsLocalizedArray)) {
			foreach ($subjectsLocalizedArray as $locale => $subjectList) {
				$subjects[$locale] = array();
				foreach ($subjectList as $subjectText) {
					// Check if all subjects are in single elemnt separated by ;
					$curSubjects = explode(';', $subjectText);
					foreach ($curSubjects as $curSubject) {
						$subjects[$locale][] = $curSubject;
					}
				}
			}
		}
		$submissionSubjectDAO->insertSubjects($subjects, $this->_submission->getCurrentPublication()->getId());

		// Process article disciplines
		$submissionDisciplineDAO = DAORegistry::getDAO('SubmissionDisciplineDAO');
		$disciplineLocalizedArray = $this->_getLocalizedElements($this->_articleNode, 'discipline', 'disciplines');
		$disciplines = array();
		if (!empty($disciplineLocalizedArray)) {
			foreach ($disciplineLocalizedArray as $locale => $disciplineList) {
				$disciplines[$locale] = array();
				foreach ($disciplineList as $disciplineText) {
					// Check if all disciplines are in a single element separated by ;
					$curDisciplines = explode(';', $disciplineText);
					foreach ($curDisciplines as $curDiscipline) {
						$disciplines[$locale][] = $curDiscipline;
					}
				}
			}
		}
		$submissionDisciplineDAO->insertDisciplines($disciplines, $this->_submission->getCurrentPublication()->getId());

		// Handle PDF galleys
		$this->_handlePDFGalleyNode();

		// Index the article
		$articleSearchIndex = new ArticleSearchIndex();
		$articleSearchIndex->submissionMetadataChanged($this->_submission);
		$articleSearchIndex->submissionFilesChanged($this->_submission);
		$articleSearchIndex->submissionChangesFinished();

		$returner = array(
			'issue' => $this->_issue,
			'section' => $this->_section,
			'article' => $this->_submission
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

		// Set a title for issue (required field)
		$issueTitle = "Vol. " . $this->_volume . ", No. " . $this->_number . " (" . $year . ")";

		// Create new issue
		$this->_issue = new Issue();
		$this->_issue->setJournalId($this->_journal->getId());
		$this->_issue->setTitle($issueTitle, $this->_primaryLocale);
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

		// Given name -- required field
		$fnameLocalizedArray = $this->_getLocalizedElements($authorNode, 'fname', 'fnames');
		// Use locale array if present, otherwise use empty char string
		if (!empty($fnameLocalizedArray)) {
			foreach ($fnameLocalizedArray as $locale => $fnameList) {
				foreach ($fnameList as $fnameText) {
					$author->setGivenName($fnameText, $locale);
				}
			}
		} else {
			$author->setGivenName('', $this->_primaryLocale);
			$fnameLocalizedArray = array(
				$this->_primaryLocale => array('')
			);
		}

		// Family name
		$lnameLocalizedArray = $this->_getLocalizedElements($authorNode, 'lname', 'lnames');
		// Use locale array if present, otherwise use journal name
		if (!empty($lnameLocalizedArray)) {
			foreach ($lnameLocalizedArray as $locale => $lnameList) {
				foreach ($lnameList as $lnameText) {
					$author->setFamilyName($lnameText, $locale);
				}
			}
		} else {
			$author->setFamilyName($this->_journal->getName($this->_primaryLocale), $this->_primaryLocale);
			$lnameLocalizedArray = array(
				$this->_primaryLocale => array(
					$this->_journal->getName($this->_primaryLocale)
				)
			);
		}

		// Middle name and suffix
		$mnameLocalizedArray = $this->_getLocalizedElements($authorNode, 'mname', 'mnames');
		$suffixLocalizedArray = $this->_getLocalizedElements($authorNode, 'suffix', 'suffixes');

		// If we have either a middle name or suffix, create a prefered name
		if (!empty($mnameLocalizedArray) || !empty($suffixLocalizedArray)) {
			// For adding localized prefered names, loop over given/first names
			// as they are the only required name field and will be present
			// with at least the primary locale and empty string
			foreach ($fnameLocalizedArray as $locale => $fnameList) {
				$fname = $fnameLocalizedArray[$locale][0];
				$mname = $mnameLocalizedArray[$locale][0];
				$lname = $lnameLocalizedArray[$locale][0];
				$suffix = $suffixLocalizedArray[$locale][0];
				$author->setPreferredPublicName(trim("$fname $mname $lname $suffix"), $locale);
			}
		}

		// Affiliation
		$affiliationLocalizedArray = $this->_getLocalizedElements($authorNode, 'institution', 'institutions');

		// Use locale array if present, otherwise use empty string
		if(!empty($affiliationLocalizedArray)) {
			foreach ($affiliationLocalizedArray as $locale => $affiliationList) {
				foreach ($affiliationList as $affiliationText) {
					$author->setAffiliation($affiliationText, $locale);
				}
			}
		} else {
			$author->setAffiliation('', $this->_primaryLocale);
		}

		$email = $authorNode->getChildValue('email');
		$author->setEmail(isset($email) ? $email : $this->_defaultEmail);

		$author->setSequence($authorIndex + 1); // 1-based
		$author->setSubmissionId($this->_submission->getId());
		$author->setData('publicationId', $this->_submission->getCurrentPublication()->getId());
		$author->setIncludeInBrowse(true);
		$author->setPrimaryContact($authorIndex == 0 ? 1 : 0);

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
		$author->setSubmissionId($this->_submission->getId());
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

		$articleGalleyDao = DAORegistry::getDao('ArticleGalleyDAO');
		$articleGalley = $articleGalleyDao->newDataObject();
		$articleGalley->setData('publicationId', $this->_submission->getCurrentPublication()->getId());
		$articleGalley->setName($pdfFilename, $this->_primaryLocale);
		$articleGalley->setSequence(1);
		$articleGalley->setLabel('PDF');
		$articleGalley->setLocale($this->_primaryLocale);
		$articleGalleyDao->insertObject($articleGalley);

		// Add the PDF file and link representation with submission file
		$genreDao = DAORegistry::getDAO('GenreDAO');
		$genre = $genreDao->getByKey('SUBMISSION', $this->_journal->getId());

		$submissionFileManager = new SubmissionFileManager($this->_journal->getId(), $this->_submission->getId());

		import('lib.pkp.classes.submission.SubmissionFile'); // constants
		$submissionFile = $submissionFileManager->copySubmissionFile(
			$this->_pdfPath,
			SUBMISSION_FILE_PROOF,
			$this->_editor->getId(),
			null,
			$genre->getId(),
			ASSOC_TYPE_REPRESENTATION,
			$articleGalley->getId()
		);
		$articleGalley->setFileId($submissionFile->getFileId());
		$articleGalleyDao->updateObject($articleGalley);
	}

	function _getArticleTitle() {
		$titleLocalizedArray = $this->_getLocalizedElements($this->_articleNode, 'title', 'titles');

		// Check if we have a title for primary locale, if not assign first title element to primary locale
		$containsPrimaryLocale = false;
		foreach ($titleLocalizedArray as $locale => $titleList) {
			if ($locale === $this->_primaryLocale) {
				$containsPrimaryLocale = true;
				break;
			}
		}

		if (!$containsPrimaryLocale) {
			$titleLocalizedArray[$this->_primaryLocale] = $titleLocalizedArray[0];
		}
		$this->_articleTitleLocalizedArray = $titleLocalizedArray;
		$this->_articleTitle = $this->_articleTitleLocalizedArray[$this->_primaryLocale][0];
	}

	function _cleanupFailure() {
		$issueDao = DAORegistry::getDAO('IssueDAO');
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');

		foreach ($this->_dependentItems as $dependentItem) {
			switch ($dependentItem) {
				case 'issue':
					$issueDao->deleteObject($this->_issue);
					break;
				case 'article':
					$submissionDao->deleteObject($this->_submission);
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

	/**
	 * Extract localized elements from XMLNode
	 *
	 * @param $primaryNode XMLNode Element-containing node
	 * @param $elementNameSingular string node name, singular form
	 * @param $elementNamePlural string node name, plural form
	 * @return array Array of localized text [locale => [text, text]]
	 */
	function _getLocalizedElements($primaryNode, $elementNameSingular, $elementNamePlural) {

		$elementText = '';
		$returner = array();

		// Search for singular element first
		$elementNode = $primaryNode->getChildByName($elementNameSingular);
		if ($elementNode) {
			$elementLocale = $elementNode->getAttribute('locale');
			if (!$elementLocale) $elementLocale= $this->_primaryLocale;
			$elementText = $elementNode->getValue();
			$elementText = html_entity_decode($elementText, ENT_HTML5);
			if (isset($elementText)) {
				// Add single element text item to locale array
				$returner[$elementLocale] = array($elementText);
			}
		} else {
			// If none found, search for element's parent node
			$elementsNode = $primaryNode->getChildByName($elementNamePlural);
			if ($elementsNode) {
				for ($i = 0; $elementNode = $elementsNode->getChildByName($elementNameSingular, $i); $i++) {
					$elementLocale = $elementNode->getAttribute('locale');
					if (!$elementLocale) $elementLocale = $this->_primaryLocale;
					$elementText = $elementNode->getValue();
					$elementText = html_entity_decode($elementText, ENT_HTML5);
					if (isset($elementText)) {
						if (empty($returner[$elementLocale])) {
							// If no array exists for this locale, create one
							$returner[$elementLocale] = array($elementText);
						} else {
							// Otherwise push new element to existing locale array
							array_push($returner[$elementLocale], $elementText);
						}
					}
				}
			}
		}

		return $returner;
	}
}

?>
