<?php

class UndevDifferenceEngine extends DifferenceEngine
{
	protected function getRevisionHeader(Revision $rev, $complete = '')
	{
		$lang = $this->getLanguage();
		$user = $this->getUser();
		$revtimestamp = $rev->getTimestamp();
		$timestamp = $lang->userTimeAndDate($revtimestamp, $user);
		$dateofrev = $lang->userDate($revtimestamp, $user);
		$timeofrev = $lang->userTime($revtimestamp, $user);

		$header = $this->msg(
			$rev->isCurrent() ? 'currentrev-asof' : 'revisionasof',
			$timestamp,
			$dateofrev,
			$timeofrev
		)->escaped();

		if ($complete !== 'complete') {
			return $header;
		}

		$title = $rev->getTitle();

		$header = Linker::linkKnown($title, $header, array(),
			array('oldid' => $rev->getID()));

		return $header;
	}

	function showDiffPage($diffOnly = false)
	{
		wfProfileIn(__METHOD__);

		# Allow frames except in certain special cases
		$out = $this->getOutput();
		$out->allowClickjacking();
		$out->setRobotPolicy('noindex,nofollow');

		if (!$this->loadRevisionData()) {
			wfProfileOut(__METHOD__);
			return;
		}

		$user = $this->getUser();
		$permErrors = $this->mNewPage->getUserPermissionsErrors('read', $user);
		if ($this->mOldPage) { # mOldPage might not be set, see below.
			$permErrors = wfMergeErrorArrays($permErrors,
				$this->mOldPage->getUserPermissionsErrors('read', $user));
		}
		if (count($permErrors)) {
			wfProfileOut(__METHOD__);
			throw new PermissionsError('read', $permErrors);
		}

		$rollback = '';
		$undoLink = '';

		$query = array();
		# Carry over 'diffonly' param via navigation links
		if ($diffOnly != $user->getBoolOption('diffonly')) {
			$query['diffonly'] = $diffOnly;
		}
		# Cascade unhide param in links for easy deletion browsing
		if ($this->unhide) {
			$query['unhide'] = 1;
		}

		# Check if one of the revisions is deleted/suppressed
		$deleted = $suppressed = false;
		$allowed = $this->mNewRev->userCan(Revision::DELETED_TEXT, $user);

		$revisionTools = array();

		# mOldRev is false if the difference engine is called with a "vague" query for
		# a diff between a version V and its previous version V' AND the version V
		# is the first version of that article. In that case, V' does not exist.
		if ($this->mOldRev === false) {
			$out->setPageTitle($this->msg('difference-title', $this->mNewPage->getPrefixedText()));
			$samePage = true;
			$oldHeader = '';
		} else {
			wfRunHooks('DiffViewHeader', array($this, $this->mOldRev, $this->mNewRev));

			if ($this->mNewPage->equals($this->mOldPage)) {
				$out->setPageTitle($this->msg('difference-title', $this->mNewPage->getPrefixedText()));
				$samePage = true;
			} else {
				$out->setPageTitle($this->msg('difference-title-multipage', $this->mOldPage->getPrefixedText(),
					$this->mNewPage->getPrefixedText()));
				$out->addSubtitle($this->msg('difference-multipage'));
				$samePage = false;
			}

			if ($samePage && $this->mNewPage->quickUserCan('edit', $user)) {
				if ($this->mNewRev->isCurrent() && $this->mNewPage->userCan('rollback', $user)) {
					$rollbackLink = Linker::generateRollback($this->mNewRev, $this->getContext());
					if ($rollbackLink) {
						$out->preventClickjacking();
						$rollback = '&#160;&#160;&#160;' . $rollbackLink;
					}
				}
				if (!$this->mOldRev->isDeleted(Revision::DELETED_TEXT) && !$this->mNewRev->isDeleted(Revision::DELETED_TEXT)) {
					$undoLink = Html::element('a', array(
							'href' => $this->mNewPage->getLocalURL(array(
									'action' => 'edit',
									'undoafter' => $this->mOldid,
									'undo' => $this->mNewid)),
							'title' => Linker::titleAttrib('undo')
						),
						$this->msg('editundo')->text()
					);
					$revisionTools[] = $undoLink;
				}
			}

			if ($this->mOldRev->isMinor()) {
				$oldminor = ChangesList::flag('minor');
			} else {
				$oldminor = '';
			}

			$ldel = $this->revisionDeleteLink($this->mOldRev);
			$oldRevisionHeader = $this->getRevisionHeader($this->mOldRev, 'complete');
			$oldChangeTags = ChangeTags::formatSummaryRow($this->mOldTags, 'diff');
			$oldHeader = '<div id="mw-diff-otitle1"><strong>' . $oldRevisionHeader . '</strong></div>' .
				'<div id="mw-diff-otitle2">' .
				Linker::revUserLink($this->mOldRev, !$this->unhide) . '</div>' .
				'<div id="mw-diff-otitle3">' . $oldminor .
				Linker::revComment($this->mOldRev, !$diffOnly, !$this->unhide) . $ldel . '</div>' .
				'<div id="mw-diff-otitle5">' . $oldChangeTags[0] . '</div>';

			if ($this->mOldRev->isDeleted(Revision::DELETED_TEXT)) {
				$deleted = true; // old revisions text is hidden
				if ($this->mOldRev->isDeleted(Revision::DELETED_RESTRICTED)) {
					$suppressed = true; // also suppressed
				}
			}

			# Check if this user can see the revisions
			if (!$this->mOldRev->userCan(Revision::DELETED_TEXT, $user)) {
				$allowed = false;
			}
		}

		if ($this->mNewRev->isMinor()) {
			$newminor = ChangesList::flag('minor');
		} else {
			$newminor = '';
		}

		# Handle RevisionDelete links...
		$rdel = $this->revisionDeleteLink($this->mNewRev);

		# Allow extensions to define their own revision tools
		wfRunHooks('DiffRevisionTools', array($this->mNewRev, &$revisionTools));
		$formattedRevisionTools = array();
		// Put each one in parentheses (poor man's button)
		foreach ($revisionTools as $tool) {
			$formattedRevisionTools[] = $this->msg('parentheses')->rawParams($tool)->escaped();
		}
		$newRevisionHeader = $this->getRevisionHeader($this->mNewRev, 'complete');
		$newChangeTags = ChangeTags::formatSummaryRow($this->mNewTags, 'diff');

		$newHeader = '<div id="mw-diff-ntitle1"><strong>' . $newRevisionHeader . '</strong></div>' .
			'<div id="mw-diff-ntitle2">' . Linker::revUserLink($this->mNewRev, !$this->unhide) .
			" $rollback</div>" .
			'<div id="mw-diff-ntitle3">' . $newminor .
			Linker::revComment($this->mNewRev, !$diffOnly, !$this->unhide) . $rdel . '</div>' .
			'<div id="mw-diff-ntitle5">' . $newChangeTags[0] . '</div>';

		if ($this->mNewRev->isDeleted(Revision::DELETED_TEXT)) {
			$deleted = true; // new revisions text is hidden
			if ($this->mNewRev->isDeleted(Revision::DELETED_RESTRICTED)) {
				$suppressed = true; // also suppressed
			}
		}

		# If the diff cannot be shown due to a deleted revision, then output
		# the diff header and links to unhide (if available)...
		if ($deleted && (!$this->unhide || !$allowed)) {
			$this->showDiffStyle();
			$multi = $this->getMultiNotice();
			$out->addHTML($this->addHeader('', $oldHeader, $newHeader, $multi));
			if (!$allowed) {
				$msg = $suppressed ? 'rev-suppressed-no-diff' : 'rev-deleted-no-diff';
				# Give explanation for why revision is not visible
				$out->wrapWikiMsg("<div id='mw-$msg' class='mw-warning plainlinks'>\n$1\n</div>\n",
					array($msg));
			} else {
				# Give explanation and add a link to view the diff...
				$link = $this->getTitle()->getFullURL($this->getRequest()->appendQueryValue('unhide', '1', true));
				$msg = $suppressed ? 'rev-suppressed-unhide-diff' : 'rev-deleted-unhide-diff';
				$out->wrapWikiMsg("<div id='mw-$msg' class='mw-warning plainlinks'>\n$1\n</div>\n", array($msg, $link));
			}
			# Otherwise, output a regular diff...
		} else {
			# Add deletion notice if the user is viewing deleted content
			$notice = '';
			if ($deleted) {
				$msg = $suppressed ? 'rev-suppressed-diff-view' : 'rev-deleted-diff-view';
				$notice = "<div id='mw-$msg' class='mw-warning plainlinks'>\n" . $this->msg($msg)->parse() . "</div>\n";
			}
			$this->showDiff($oldHeader, $newHeader, $notice);
			if (!$diffOnly) {
				$this->renderNewRevision();
			}
		}
		wfProfileOut(__METHOD__);
	}
} 