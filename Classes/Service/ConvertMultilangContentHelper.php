<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Torben Hansen <derhansen@gmail.com>, Skyfillers GmbH
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Class with methods used for mulitlingual content conversion
 */
class Tx_SfTv2fluidge_Service_ConvertMultilangContentHelper implements t3lib_Singleton {

	/**
	 * @var Tx_SfTv2fluidge_Service_SharedHelper
	 */
	protected $sharedHelper;

	/**
	 * @var t3lib_refindex
	 */
	protected $refIndex;

	/**
	 * @var string
	 */
	protected $flexformConversionOption = 'merge';

	/**
	 * @var string
	 */
	protected $insertRecordsConversionOption = 'convertAndTranslate';

	/**
	 * @var array
	 */
	protected $langIsoCodes = array();

	/**
	 * DI for shared helper
	 *
	 * @param Tx_SfTv2fluidge_Service_SharedHelper $sharedHelper
	 * @return void
	 */
	public function injectSharedHelper(Tx_SfTv2fluidge_Service_SharedHelper $sharedHelper) {
		$this->sharedHelper = $sharedHelper;
	}

	/**
	 * DI for t3lib_refindex
	 *
	 * @param t3lib_refindex t3lib_refindex
	 * @return void
	 */
	public function injectRefIndex(t3lib_refindex $refIndex) {
		$this->refIndex = $refIndex;
	}

	/**
	 * @param array $formdata
	 */
	public function initFormData($formdata) {
		$this->flexformConversionOption = $formdata['convertflexformoption'];
		$this->insertRecordsConversionOption = $formdata['convertinsertrecords'];
	}

	/**
	 * Clones all GridElements which are configured for "All languages" and creates a single GridElement
	 * for each page translation. Also sets the language for the original GridElement to 0 (default language)
	 *
	 * @param int $pageUid
	 * @return int Amount of cloned GridElements
	 */
	public function cloneLangAllGEs($pageUid) {
		$cloned = 0;
		$pageLanguages = $this->getAvailablePageTranslations(27);
		$gridElements = $this->getCeGridElements($pageUid, -1); // All GridElements with language = all
		$this->langIsoCodes = $this->sharedHelper->getLanguagesIsoCodes();
		
		foreach ($gridElements as $contentElementUid) {
			$origContentElement = $this->sharedHelper->getContentElement($contentElementUid);
			foreach ($pageLanguages as $langUid) {
				$translationContentUid = $this->addTranslationContentElement($origContentElement, $langUid, $origContentElement['uid']);
				$this->updateShortcutElements($contentElementUid, $langUid, $translationContentUid);
				$cloned += 1;
			}
			$origContentElement['sys_language_uid'] = 0;
			$origUid = $origContentElement['uid'];
			unset ($origContentElement['uid']);
			$GLOBALS['TYPO3_DB']->exec_UPDATEquery('tt_content', 'uid=' . $origUid, $origContentElement);
			$this->updateSysLanguageOfAllLanguageShortcuts($contentElementUid);
		}
		return $cloned;
	}

	/**
	 * @param array $shortcutElements
	 * @param int $contentElementUid
	 * @param int $langUid
	 */
	protected function updateShortcutElements($contentElementUid, $langUid, $translationContentUid = 0) {
		$shortcutElements = $this->getShortcutElements($contentElementUid, $langUid);
		$translationContentUid = (int)$translationContentUid;
		if ($this->insertRecordsConversionOption !== 'keep') {
			foreach ($shortcutElements as $shortcutElement) {
				if (!empty($shortcutElement['records']) && ($shortcutElement['CType'] === 'shortcut')) {
					$records = t3lib_div::trimExplode(',', $shortcutElement['records'], TRUE);
					$recordShortcutString = 'tt_content_' . (int)$contentElementUid;
					$isShortcutRecord = FALSE;
					foreach ($records as &$record) {
						if ($record === $recordShortcutString) {
							if (($this->insertRecordsConversionOption === 'convertAndTranslate') && ($translationContentUid > 0)) {
								$record = 'tt_content_' . $translationContentUid;
							}
							$isShortcutRecord = TRUE;
							break;
						}
					}

					if ($isShortcutRecord) {
						if (!empty($records)) {
							$shortcutElement['records'] = implode(',', $records);
						}
						$languageUid = (int)$shortcutElement['sys_language_uid'];
						$origUid = NULL;
						if ($languageUid > 0) {
							$origUid = (int)$shortcutElement['l18n_parent'];
						} else {
							$origUid = (int)$shortcutElement['uid'];
						}
						$newUid = (int)$this->addTranslationContentElement($shortcutElement, $langUid, $origUid);
						if ($newUid > 0) {
							$this->refIndex->updateRefIndexTable('tt_content', $newUid);
						}
					}
				}
			}
		}
	}

	/**
	 * @param int $contentElementUid
	 */
	protected function updateSysLanguageOfAllLanguageShortcuts($contentElementUid) {
		$contentElementUid = (int)$contentElementUid;
		$shortcutElements = $this->getShortcutElements($contentElementUid);
		foreach ($shortcutElements as $shortcutElement) {
			$shortcutElementUid = (int)$shortcutElement['uid'];
			$shortcutElementLanguage = (int)$shortcutElement['sys_language_uid'];
			if (($shortcutElementUid > 0) && ($shortcutElementLanguage < 0)) {
				$GLOBALS['TYPO3_DB']->exec_UPDATEquery('tt_content', 'uid=' . $shortcutElementUid, array('sys_language_uid' => 0));
			}
		}
	}

	/**
	 * @param array $origContentElement
	 * @param int $langUid
	 * @return int
	 */
	protected function addTranslationContentElement($contentElement, $langUid, $origUid) {
		$langUid = (int)$langUid;
		$origUid = (int)$origUid;
		unset ($contentElement['uid']);
		$contentElement['sys_language_uid'] = $langUid;
		$contentElement['t3_origuid'] = (int)$origUid;
		$contentElement['l18n_parent'] = (int)$origUid;

		if (($this->flexformConversionOption !== 'exclude')) {
			if (t3lib_extMgm::isLoaded('static_info_tables')) {
				$langUid = (int)$contentElement['sys_language_uid'];
				if ($langUid > 0) {
					$forceLanguage = ($this->flexformConversionOption === 'forceLanguage');
					if ($origUid <= 0) {
						$forceLanguage = FALSE;
					}
					$contentElement['pi_flexform'] = $this->convertFlexformForTranslation($contentElement['pi_flexform'], $this->langIsoCodes[$langUid], $forceLanguage);
				}
			}
		}

		$existingTranslation = $this->sharedHelper->getTranslationForContentElementAndLanguage($origUid, $langUid);
		$existingTranslationUid = 0;
		if (!empty($existingTranslation) && is_array($existingTranslation)) {
			$existingTranslationUid = (int)$existingTranslation['uid'];
		}

		$contentElementUid = NULL;
		if ($existingTranslationUid > 0) {
			$GLOBALS['TYPO3_DB']->exec_UPDATEquery('tt_content', 'uid=' . $existingTranslationUid, $contentElement);
			$contentElementUid = $existingTranslationUid;
		} else {
			$GLOBALS['TYPO3_DB']->exec_INSERTquery('tt_content', $contentElement);
			$contentElementUid = (int)$GLOBALS['TYPO3_DB']->sql_insert_id();
		}

		return $contentElementUid;
	}

	/**
	 * @param $flexformArray
	 * @param $langIsoCode
	 * @return string
	 */
	protected function convertFlexformForTranslation($flexformString, $langIsoCode, $forceLanguage = FALSE) {
		$flexformArray = NULL;
		if (!empty($flexformString)) {
			if (!empty($langIsoCode)) {
				$flexformArray = t3lib_div::xml2array($flexformString);
				if (is_array($flexformArray)) {
					if (is_array($flexformArray['data']['sDEF']['lDEF'])) {
						foreach ($flexformArray['data'] as &$sheetData) {
							foreach ($sheetData['lDEF'] as $fieldName => &$fieldData) {
								if (is_array($fieldData)) {
									$fieldDataLang = NULL;
									$issetLangValue = FALSE;
									$fieldLangArray = $sheetData['l' . $langIsoCode][$fieldName];
									if (is_array($fieldLangArray)) {
										$fieldDataLang = $fieldLangArray['v' . $langIsoCode];
										if (!empty($fieldDataLang)) {
											$fieldData['vDEF'] = $fieldDataLang;
										} else {
											if (isset($fieldLangArray['v' . $langIsoCode]) && $forceLanguage) {
												$issetLangValue = TRUE;
											} else {
												$fieldDataLang = $fieldLangArray['vDEF'];
												if (!empty($fieldDataLang)) {
													$fieldData['vDEF'] = $fieldDataLang;
												} elseif (isset($fieldLangArray['vDEF'])) {
													$issetLangValue = TRUE;
												}
											}
										}
									}

									if (empty($fieldDataLang)) {
										$fieldDataLang = $fieldData['v' . $langIsoCode];
										if (!empty($fieldDataLang)) {
											$fieldData['vDEF'] = $fieldDataLang;
										} elseif (isset($fieldLangArray['v' . $langIsoCode])) {
											$issetLangValue = TRUE;
										}
									}

									if ($issetLangValue && $forceLanguage) {
										$fieldData['vDEF'] = $fieldDataLang;
									}
								}
							}
						}
					}
				}
			}
		}

		if (!empty($flexformArray) && is_array($flexformArray)) {
			/**
			 * @var t3lib_flexformtools $flexformTools
			 */
			$flexformTools = t3lib_div::makeInstance('t3lib_flexformtools');
			$flexformString = $flexformTools->flexArray2Xml($flexformArray, TRUE);
		}

		return $flexformString;
	}

	/**
	 * Rearranges content and translated content elements to the cloned/modified GridElements
	 *
	 * @param int $pageUid
	 * @return int Amount of updated content elements
	 */
	public function rearrangeContentElementsForGridelementsOnPage($pageUid) {
		$updated = 0;
		$gridElements = $this->getCeGridElements($pageUid, 0);
		foreach ($gridElements as $contentElementUid) {
			$contentElements = $this->getChildContentElements($contentElementUid);
			foreach ($contentElements as $contentElement) {
				$translations = $this->sharedHelper->getTranslationsForContentElement($contentElement['uid']);
				if (!empty($translations)) {
					foreach($translations as $translatedContentElement) {
						$localizedGridElement = $this->getLocalizedGridElement($contentElementUid,
							$translatedContentElement['sys_language_uid']);
						if ($localizedGridElement) {
							$origUid = $translatedContentElement['uid'];
							unset($translatedContentElement['uid']);
							$translatedContentElement['colPos'] = -1;
							$translatedContentElement['tx_gridelements_container'] = $localizedGridElement['uid'];
							$translatedContentElement['tx_gridelements_columns'] = $contentElement['tx_gridelements_columns'];
							$GLOBALS['TYPO3_DB']->exec_UPDATEquery('tt_content', 'uid=' . $origUid, $translatedContentElement);
							$updated += 1;
						}
					}
				}

				if ($contentElement['sys_language_uid'] > 0) {
					// Rearrage CE to new localized GEs
					$localizedGridElement = $this->getLocalizedGridElement($contentElementUid,
																				$contentElement['sys_language_uid']);
					$origUid = $contentElement['uid'];
					unset($contentElement['uid']);
					$contentElement['tx_gridelements_container'] = $localizedGridElement['uid'];
					$GLOBALS['TYPO3_DB']->exec_UPDATEquery('tt_content', 'uid=' . $origUid, $contentElement);
					$updated += 1;
				}
			}
		}
		return $updated;
	}

	/**
	 * Returns an array with UIDs of languages for the given page (default language not included)
	 *
	 * @param int $pageUid
	 * @return array
	 */
	public function getAvailablePageTranslations($pageUid) {
		$fields = '*';
		$table = 'pages_language_overlay';
		$where = '(pid=' . (int)$pageUid . ') '.
					' AND (sys_language_uid > 0)';

		$res = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where, '', '', '');

		$languages = array();
		if ($res) {
			foreach($res as $lang) {
				$languages[] = $lang['sys_language_uid'];
			}
		}
		return $languages;
	}

	/**
	 * Returns an array with UIDs of GridElements, which are configured for "All languages"
	 *
	 * @param int $pageUid
	 * @param int $langUid
	 * @return array
	 */
	public function getCeGridElements($pageUid, $langUid) {
		$fields = 'uid';
		$table = 'tt_content';
		$where = 'pid=' . (int)$pageUid . ' AND CType = "gridelements_pi1" AND sys_language_uid = ' . (int)$langUid;

		$res = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where, '', '', '');

		$gridElements = array();
		if ($res) {
			foreach($res as $ge) {
				$gridElements[] = $ge['uid'];
			}
		}
		return $gridElements;
	}

	/**
	 * @param int $uidContentElement
	 * @return array
	 */
	public function getShortcutElements($uidContentElement, $langUid = 0) {
		$fields = 'tt_content.*';
		$table = 'sys_refindex, tt_content';
		$langUid = (int)$langUid;
		$langWhere = '';
		if ($langUid > 0) {
			$langWhere = ' OR ((tt_content.sys_language_uid = ' . $langUid . ')  AND (tt_content.l18n_parent = 0))';
		}
		$where = '(tt_content.CType = \'shortcut\')' .
					' AND (tt_content.uid = sys_refindex.recuid)' .
					' AND ('.
						'(tt_content.sys_language_uid IN (0,-1) AND (tt_content.l18n_parent = 0))' .
						$langWhere .
					')' .
					' AND (sys_refindex.ref_uid = ' . (int)$uidContentElement . ')';

		$res = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where, '', '', '');
		if (empty($res)) {
			$res = array();
		}

		return $res;
	}

	/**
	 * Returns the uid of the localized content element (grid element) for the given uid
	 *
	 * @param int $uidContentElement
	 * @param int $langUid
	 * @return array
	 */
	public function getLocalizedGridElement($uidContentElement, $langUid) {
		$fields = 'uid';
		$table = 'tt_content';
		$where = 'l18n_parent=' . (int)$uidContentElement . ' AND CType = "gridelements_pi1" AND sys_language_uid = ' . (int)$langUid;

		return $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow($fields, $table, $where, '', '', '');
	}

	/**
	 * Returns an array of tt_content UIDs, which are child elements for the given content element uid
	 *
	 * @param int $uidContent
	 * @return array
	 */
	public function getChildContentElements($uidContent) {
		$fields = '*';
		$table = 'tt_content';
		$where = 'tx_gridelements_container=' . (int)$uidContent . ' AND l18n_parent=0';

		return $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where, '', '', '');
	}

}