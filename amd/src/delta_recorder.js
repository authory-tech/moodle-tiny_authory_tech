// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @module     tiny_authory_tech/delta_recorder
 * @category   TinyMCE Editor
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 */

import {call} from 'core/ajax';
import {create} from 'core/modal_factory';
import {get_string as getString} from 'core/str';
import {save, cancel, hidden} from 'core/modal_events';
import $ from 'jquery';
import {iconUrl, iconGrayUrl, tooltipCss} from 'tiny_authory_tech/common';
import Autosave from 'tiny_authory_tech/authory_tech_autosave';
import DocumentView from 'tiny_authory_tech/document_view';

/**
 * Compute a Quill-style delta between two strings using a prefix/suffix trim.
 * Returns an ops array: [{retain:N}, {insert:"..."}, {delete:N}].
 * The result only contains ops that are non-trivial (no empty insert/delete).
 *
 * @param {string} oldStr
 * @param {string} newStr
 * @returns {Array}
 */
function computeDelta(oldStr, newStr) {
    if (oldStr === newStr) {
        return [];
    }

    // Find common prefix length
    let prefixLen = 0;
    const minLen = Math.min(oldStr.length, newStr.length);
    while (prefixLen < minLen && oldStr[prefixLen] === newStr[prefixLen]) {
        prefixLen++;
    }

    // Find common suffix length (not overlapping the prefix)
    let suffixLen = 0;
    while (
        suffixLen < oldStr.length - prefixLen &&
        suffixLen < newStr.length - prefixLen &&
        oldStr[oldStr.length - 1 - suffixLen] === newStr[newStr.length - 1 - suffixLen]
    ) {
        suffixLen++;
    }

    const ops = [];
    if (prefixLen > 0) {
        ops.push({retain: prefixLen});
    }
    const deleted = oldStr.slice(prefixLen, oldStr.length - suffixLen);
    const inserted = newStr.slice(prefixLen, newStr.length - suffixLen);
    if (deleted.length > 0) {
        ops.push({delete: deleted.length});
    }
    if (inserted.length > 0) {
        ops.push({insert: inserted});
    }
    // Trailing retain is omitted (implied by Quill Delta convention)
    return ops;
}

/**
 * Map an InputEvent.inputType to the meta field stored with each delta.
 *
 * @param {string} inputType
 * @returns {string}
 */
function classifyInputType(inputType) {
    switch (inputType) {
        case 'insertFromPaste':
        case 'insertFromPasteAsQuotation':
            return 'paste';
        case 'historyUndo':
            return 'undo';
        case 'historyRedo':
            return 'redo';
        case 'insertReplacementText':
            return 'autoinsert';
        default:
            return 'keystroke';
    }
}

export const register = (editor, interval, userId, hasApiKey, MODULES, Rubrics, submission, quizInfo, pasteSetting) => {

    var isStudent = !($('#body').hasClass('teacher_admin'));
    var intervention = $('#body').hasClass('intervention');
    var userid = userId;
    var courseid = M.cfg.courseId;
    var editorid = editor?.id;
    var cmid = M.cfg.contextInstanceId;
    var questionid = 0;
    var quizSubmit = $('#mod_quiz-next-nav');
    let assignSubmit = $('#id_submitbutton');
    var syncInterval = interval ? interval * 1000 : 10000;
    var isFullScreen = false;
    var user = null;
    let ur = window.location.href;
    let parm = new URL(ur);
    let modulesInfo = getModulesInfo(ur, parm, MODULES);
    var resourceId = modulesInfo.resourceId;
    var modulename = modulesInfo.name;
    var errorAlert = true;
    let PASTE_SETTING = pasteSetting || 'allow';
    let shouldBlockPaste = false;
    let isPasteAllowed = false;

    // Delta recording state
    let _prevText = '';
    let _pendingDeltas = [];
    let _beforeSetContent = ''; // snapshot of _prevText taken just before SetContent fires

    if (ur.includes('pdfannotator')) {
        document.addEventListener('click', e => {
            if (e.target.className === "dropdown-item comment-edit-a") {
                let id = e.target.id;
                resourceId = id.replace('editButton', '');
                localStorage.setItem('isEditing', '1');
            }
            if (e.target.id === 'commentSubmit') {
                syncData();
            }
        });
    }

    const postOne = async(methodname, args) => {
        try {
            const response = await call([{
                methodname,
                args,
            }])[0];
            if (response) {
                setTimeout(() => {
                    Autosave.updateSavingState('saved');
                }, 1000);
            }
            return response;
        } catch (error) {
            Autosave.updateSavingState('offline');
            window.console.error('Error in postOne:', error);
            throw error;
        }
    };

    call([{
            methodname: 'core_user_get_users_by_field',
            args: {field: 'id', values: [userid]},
        }])[0].done(response => {
            user = response[0];
        }).fail((ex) => {
            window.console.error('Error fetching user data:', ex);
        });

    /**
     * Attaches a click handler to a submit button that flushes pending delta
     * data before allowing the native submit to proceed.
     * @param {jQuery} submitBtn
     */
    const setupSubmitHandler = (submitBtn) => {
        submitBtn.on('click', async function(e) {
            e.preventDefault();
            if (_pendingDeltas.length > 0) {
                // eslint-disable-next-line
                syncData().then(() => {
                    submitBtn.off('click').click();
                });
            } else {
                submitBtn.off('click').click();
            }
            localStorage.removeItem('lastCopyCutContent');
        });
    };

    setupSubmitHandler(assignSubmit);
    setupSubmitHandler(quizSubmit);

    const getModal = () => {

        Promise.all([
            getString('tiny_authory_tech_srcurl', 'tiny_authory_tech'),
            getString('tiny_authory_tech_srcurl_des', 'tiny_authory_tech'),
            getString('tiny_authory_tech_placeholder', 'tiny_authory_tech')
        ]).then(function([title, titledes, placeholder]) {

            return create({
                type: 'SAVE_CANCEL',
                title: `<div><div class="tiny-authory_tech-title-text">${title}</div>
                <span class="tiny-authory_tech-title-description ">${titledes}</span></div>`,
                body: `<textarea  class="form-control inputUrl" value="" id="inputUrl" placeholder="${placeholder}"></textarea>`,
                removeOnClose: true,
            })
                .done(modal => {
                    modal.getRoot().addClass('tiny-authory_tech-modal');
                    modal.show();
                    var lastEvent = '';

                    modal.getRoot().on(save, function() {

                        var number = document.getElementById("inputUrl").value.trim();

                        if (number === "" || number === null || number === undefined) {
                            editor.execCommand('Undo');
                            // eslint-disable-next-line
                            getString('pastewarning', 'tiny_authory_tech').then(str => alert(str));
                        } else {
                            editor.execCommand('Paste');
                        }

                        postOne('authory_tech_user_comments', {
                            modulename: modulename,
                            cmid: cmid,
                            resourceid: resourceId,
                            courseid: courseid,
                            usercomment: number,
                            timemodified: Date.now(),
                            editorid: editorid ? editorid : ""
                        });

                        lastEvent = 'save';
                        modal.destroy();
                    });
                    modal.getRoot().on(cancel, function() {
                        editor.execCommand('Undo');
                        lastEvent = 'cancel';
                    });

                    modal.getRoot().on(hidden, function() {
                        if (lastEvent != 'cancel' && lastEvent != 'save') {
                            editor.execCommand('Undo');
                        }
                    });
                    return modal;
                });
        }).catch(error => window.console.error(error));

    };

    // -------------------------------------------------------------------------
    // Delta recording: capture content changes via TinyMCE's input event.
    // -------------------------------------------------------------------------

    editor.on('input', function(e) {
        const nextText = editor.getContent({format: 'text'}) || '';
        const ops = computeDelta(_prevText, nextText);
        if (ops.length === 0) {
            _prevText = nextText;
            return;
        }
        // classifyInputType handles 'insertFromPaste' → 'paste' natively
        const meta = classifyInputType(e.inputType || '');
        _pendingDeltas.push({t: Date.now(), d: ops, meta});
        _prevText = nextText;
    });

    // -------------------------------------------------------------------------
    // Paste blocking / cite-source modal (unchanged logic from autosaver.js)
    // -------------------------------------------------------------------------

    // In this TinyMCE version, SetContent → execcommand(mceInsertContent) → Paste
    // fires in that order. Paste delta is captured in execcommand using _beforeSetContent.
    editor.on('Paste', async(e) => {
        customTooltip();
        const pastedContent = (e.clipboardData || e.originalEvent.clipboardData).getData('text');
        if (!pastedContent) {
            return;
        }
        const trimmedPastedContent = pastedContent.trim();
        const lastCopyCutContent = localStorage.getItem('lastCopyCutContent');
        const isFromOwnEditor = lastCopyCutContent && trimmedPastedContent === lastCopyCutContent;

        if (isStudent && intervention) {

            if (PASTE_SETTING === 'block') {
                if (!isFromOwnEditor) {
                    e.preventDefault();
                    shouldBlockPaste = true;
                    isPasteAllowed = false;
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    getString('paste_blocked', 'tiny_authory_tech').then(str => {
                       return editor.windowManager.alert(str);
                    }).catch(error => window.console.error(error));
                    setTimeout(() => {
                        isPasteAllowed = true;
                        shouldBlockPaste = false;
                    }, 100);
                    return;
                }
                shouldBlockPaste = false;
                isPasteAllowed = true;
                return;
            }
            if (PASTE_SETTING === 'cite_source') {
                if (!isFromOwnEditor) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    getModal();
                }
                isPasteAllowed = true;
                return;
            }
        }
        isPasteAllowed = true;
    });

    editor.on('Redo', async() => {
        customTooltip();
        if (isStudent && intervention) {
            getModal();
        }
    });

    editor.on('keyDown', (e) => {
        customTooltip();
        const isPasteAttempt = (e.key === 'v' || e.key === 'V') &&
        (e.ctrlKey || e.metaKey);
        if (isPasteAttempt && isStudent && intervention && PASTE_SETTING === 'block' && !isPasteAllowed) {
            setTimeout(() => {
                isPasteAllowed = true;
            }, 100);
        }
    });

    editor.on('execcommand', function(e) {
        if (e.command === "mceInsertContent") {
            const contentObj = e.value;
            const isPaste = contentObj && typeof contentObj === 'object' && contentObj.paste === true;

            if (isPaste) {
                if (shouldBlockPaste) {
                    shouldBlockPaste = false;
                    e.preventDefault();
                    editor.undoManager.undo();
                    return;
                }
                let tempDiv = document.createElement('div');
                tempDiv.innerHTML = contentObj.content || contentObj;
                let pastedText = tempDiv.textContent || tempDiv.innerText || '';
                const lastCopyCutContent = localStorage.getItem('lastCopyCutContent');
                const isFromOwnEditor = lastCopyCutContent && pastedText.trim() === lastCopyCutContent;

                if (isStudent && intervention && PASTE_SETTING === 'block' && !isFromOwnEditor) {
                    isPasteAllowed = false;
                    editor.undoManager.undo();
                    return;
                }

                // SetContent already ran and updated _prevText. Use _beforeSetContent
                // (saved at the start of SetContent) to get the pre-paste snapshot.
                const nextText = editor.getContent({format: 'text'}) || '';
                const ops = computeDelta(_beforeSetContent, nextText);
                if (ops.length > 0) {
                    _pendingDeltas.push({t: Date.now(), d: ops, meta: 'paste'});
                    _prevText = nextText;
                }
            }
        }
    });

    const saveSelectionToStorage = () => {
        const selectedContent = editor.selection.getContent({format: 'text'});
        localStorage.setItem('lastCopyCutContent', selectedContent.trim());
    };
    editor.on('Cut', saveSelectionToStorage);
    editor.on('Copy', saveSelectionToStorage);

    editor.on('init', () => {
        customTooltip();
        localStorage.removeItem('lastCopyCutContent');
        _prevText = editor.getContent({format: 'text'}) || '';
    });

    editor.on('SetContent', (ev) => {
        customTooltip();
        const nextText = editor.getContent({format: 'text'}) || '';
        // Save pre-change snapshot so execcommand(mceInsertContent, paste=true) can
        // compute the paste delta — in this TinyMCE version SetContent fires before
        // execcommand, so _prevText is already stale when execcommand runs.
        if (!ev.initial) {
            _beforeSetContent = _prevText;
        }
        _prevText = nextText;
    });

    editor.on('FullscreenStateChanged', (e) => {
        let view = new DocumentView(user, Rubrics, submission, modulename, editor, quizInfo);
        isFullScreen = e.state;
        try {
            if (!e.state) {
                view.normalMode();
            } else {
                view.fullPageMode();
            }
        } catch (error) {
            if (errorAlert) {
                errorAlert = false;
                getString('fullmodeerror', 'tiny_authory_tech').then(str => {
                    return editor.windowManager.alert(str);
                }).catch(error => window.console.error(error));
            }
            view.normalMode();
            window.console.error('Error ResizeEditor event:', error);
        }
    });

    // -------------------------------------------------------------------------
    // Sync logic
    // -------------------------------------------------------------------------

    /**
     * @param {Object} ed - TinyMCE editor instance
     * @returns {string} Raw text content of the editor body
     */
    function getRawText(ed) {
        let edId = ed?.id;
        if (edId) {
            let iframe = document.querySelector(`#${edId}_ifr`);
            let iframeBody = iframe.contentDocument?.body || iframe.contentWindow?.document?.body;
            return iframeBody?.textContent;
        }
        return "";
    }

    /**
     * @async
     * @returns {Promise<boolean>} True on success, false on failure
     */
    async function syncData() {
        checkIsPdfAnnotator();
        if (_pendingDeltas.length === 0) {
            return true;
        }
        const snapshot = _pendingDeltas.splice(0);
        editor.fire('change');
        let originalText = editor.getContent({format: 'text'});
        if (!originalText) {
            originalText = getRawText(editor);
        }
        try {
            Autosave.updateSavingState('saving');
            // eslint-disable-next-line
            await postOne('authory_tech_write_local_to_json', {
                resourceId: resourceId,
                cmid: cmid,
                modulename: modulename,
                editorid: editorid,
                "json_data": JSON.stringify(snapshot),
                originalText: originalText
            });
            return true;
        } catch (error) {
            // Re-queue on failure so data is not lost
            _pendingDeltas.unshift(...snapshot);
            window.console.error('Error submitting data:', error);
            return false;
        }
    }

    let _tooltipFullscreenState = null;
    /**
     * @returns {void}
     */
    function customTooltip() {
        if (_tooltipFullscreenState === isFullScreen) {
            return;
        }
        try {
            const tooltipText = getTooltipText();
            const menubarDiv = document.querySelectorAll('div[role="menubar"].tox-menubar');
            let classArray = [];

            if (!menubarDiv.length) {
                return;
            }
            _tooltipFullscreenState = isFullScreen;

            if (menubarDiv.length) {
                menubarDiv.forEach(function(element, index) {
                    index += 1;
                    let className = 'authory_tech-menu-' + index;
                    element.classList.add(className);
                    classArray.push(className);
                });
            }

            const authoryTechIcon = document.createElement('img');
            authoryTechIcon.src = hasApiKey ? iconUrl : iconGrayUrl;

            authoryTechIcon.setAttribute('class', 'tiny_authory_tech_StateButton');
            authoryTechIcon.style.display = 'inline-block';
            authoryTechIcon.style.width = '45px';
            authoryTechIcon.style.height = '45px';

            authoryTechState(authoryTechIcon, menubarDiv, classArray);

            for (let index in classArray) {
                const elementId = "tiny_authory_tech_StateIcon" + index;
                const tooltipId = `tiny_authory_tech_tooltip${index}`;

                tooltipText.then((text) => {
                    return setTooltip(text, document.querySelector(`#${elementId}`), tooltipId);
                }).catch(error => window.console.error(error));

                $(`#${elementId}`).on('mouseenter', function() {
                    $(this).css('position', 'relative');
                    $(`#${tooltipId}`).css(tooltipCss);
                });

                $(`#${elementId}`).on('mouseleave', function() {
                    $(`#${tooltipId}`).css('display', 'none');
                });
            }
        } catch (error) {
            window.console.error('Error setting up custom tooltip:', error);
        }
    }

    /**
     * @async
     * @returns {Promise<Object>} Object with buttonTitle and buttonDes strings
     */
    async function getTooltipText() {
        const [
            buttonTitle,
            buttonDes,
        ] = await Promise.all([
            getString('authory_tech:state:active', 'tiny_authory_tech'),
            getString('authory_tech:state:active:des', 'tiny_authory_tech'),
        ]);
        return {buttonTitle, buttonDes};
    }

    /**
     * @param {HTMLElement} authoryTechIcon - The icon element
     * @param {NodeList} menubarDiv - The menubar elements
     * @param {Array} classArray - Array of menubar class names
     * @returns {void}
     */
    function authoryTechState(authoryTechIcon, menubarDiv, classArray) {
        if (!menubarDiv) {
            return;
        }

        for (let index in classArray) {
            const rightWrapper = document.createElement('div');
            const imgWrapper = document.createElement('span');
            const iconClone = authoryTechIcon.cloneNode(true);
            const targetMenu = document.querySelector('.' + classArray[index]);
            let elementId = "tiny_authory_tech_StateIcon" + index;

            rightWrapper.style.cssText = `
                        margin-left: auto;
                        display: flex;
                        align-items: center;
                    `;

            imgWrapper.id = elementId;
            imgWrapper.style.marginLeft = '.2rem';
            imgWrapper.appendChild(iconClone);
            rightWrapper.appendChild(imgWrapper);

            let moduleIds = {
                resourceId: resourceId,
                cmid: cmid,
                modulename: modulename,
                questionid: questionid,
                userid: userid,
                courseid: courseid};

            if (isFullScreen && (modulename === 'assign' || modulename === 'forum'
                || modulename === 'lesson')) {
                let existsElement = document.querySelector('.tox-menubar[class*="authory_tech-menu-"] > div');
                let newHeader = editor.container?.childNodes[0];
                if (existsElement) {
                    existsElement.remove();
                }

                if (newHeader && !newHeader.querySelector(`span[id*=tiny_authory_tech_StateIcon]`)) {
                    rightWrapper.style.marginTop = '3px';
                    document.querySelector('#tiny_authory_tech-fullpage-right-wrapper').prepend(rightWrapper);
                }
                Autosave.destroyInstance();
                Autosave.getInstance(editor, rightWrapper, moduleIds, isFullScreen);
            } else {
                let menubar = editor?.container?.children[0]?.childNodes[0]?.childNodes[0];

                if (targetMenu && !targetMenu.querySelector(`#${elementId}`)) {
                    targetMenu.appendChild(rightWrapper);
                }
                if (modulename === 'quiz' && menubar) {
                    let wrapper = menubar.querySelector('span[id*="tiny_authory_tech_StateIcon"]');

                    if (wrapper) {
                        Autosave.destroyInstance();
                        Autosave.getInstance(editor, wrapper?.parentElement, moduleIds, isFullScreen);
                    }
                } else {
                    Autosave.destroyInstance();
                    Autosave.getInstance(editor, rightWrapper, moduleIds, isFullScreen);
                }
            }
        }
    }

    /**
     * @param {Object} text - Object with buttonTitle and buttonDes strings
     * @param {HTMLElement} authoryTechIcon - The icon element to attach tooltip to
     * @param {string} tooltipId - ID for the tooltip element
     * @returns {void}
     */
    function setTooltip(text, authoryTechIcon, tooltipId) {
        if (document.querySelector(`#${tooltipId}`)) {
            return;
        }
        if (authoryTechIcon) {
            const tooltipSpan = document.createElement('span');
            const description = document.createElement('span');
            const linebreak = document.createElement('br');
            const tooltipTitle = document.createElement('strong');

            tooltipSpan.style.display = 'none';
            tooltipTitle.textContent = text.buttonTitle;
            tooltipTitle.style.fontSize = '16px';
            tooltipTitle.style.fontWeight = 'bold';
            description.textContent = text.buttonDes;
            description.style.fontSize = '14px';

            tooltipSpan.id = tooltipId;
            tooltipSpan.classList.add(`shadow`);
            tooltipSpan.appendChild(tooltipTitle);
            tooltipSpan.appendChild(linebreak);
            tooltipSpan.appendChild(description);
            authoryTechIcon.appendChild(tooltipSpan);
        }
    }

    /**
     * @param {string} ur - Current URL string
     * @param {URL} parm - URL object for parameter extraction
     * @param {Array} MODULES - Supported module name list
     * @returns {Object|boolean} Object with resourceId and name, or false if no match
     */
    function getModulesInfo(ur, parm, MODULES) {
        fetchStrings();

        if (!MODULES.some(module => ur.includes(module))) {
            return false;
        }

        if (ur.includes("forum") && !ur.includes("assign")) {
            resourceId = parm.searchParams.get('edit');
        } else {
            resourceId = parm.searchParams.get('attempt');
        }

        if (resourceId === null) {
            resourceId = 0;
        }

        for (const module of MODULES) {
            if (ur.includes(module)) {
                modulename = module;
                if (module === "lesson" || module === "assign") {
                    resourceId = cmid;
                } else if (module === "oublog") {
                    resourceId = 0;
                }
                break;
            }
        }

        checkIsPdfAnnotator();

        return {resourceId: resourceId, name: modulename};
    }

    /**
     * @returns {void}
     */
    function fetchStrings() {
        if (!localStorage.getItem('sbTitle')) {
            Promise.all([
                getString('assignment', 'tiny_authory_tech'),
                getString('discussion', 'tiny_authory_tech'),
                getString('pluginname', 'mod_quiz'),
                getString('pluginname', 'mod_lesson'),
                getString('description', 'tiny_authory_tech'),
            ]).then(function(strings) {
                return localStorage.setItem('sbTitle', JSON.stringify(strings));
            }).catch(error => window.console.error(error));
        }
        if (!localStorage.getItem('docSideBar')) {
            Promise.all([
                getString('details', 'tiny_authory_tech'),
                getString('student_info', 'tiny_authory_tech'),
                getString('progress', 'tiny_authory_tech'),
                getString('description', 'tiny_authory_tech'),
                getString('replyingto', 'tiny_authory_tech'),
                getString('answeringto', 'tiny_authory_tech'),
                getString('importantdates', 'tiny_authory_tech'),
                getString('rubrics', 'tiny_authory_tech'),
                getString('submission_status', 'tiny_authory_tech'),
                getString('status', 'tiny_authory_tech'),
                getString('draft', 'tiny_authory_tech'),
                getString('draftnot', 'tiny_authory_tech'),
                getString('last_modified', 'tiny_authory_tech'),
                getString('gradings', 'tiny_authory_tech'),
                getString('gradenot', 'tiny_authory_tech'),
                getString('word_count', 'tiny_authory_tech'),
                getString('timeleft', 'tiny_authory_tech'),
                getString('nolimit', 'tiny_authory_tech'),
                getString('name', 'tiny_authory_tech'),
                getString('userename', 'tiny_authory_tech'),
                getString('course', 'tiny_authory_tech'),
                getString('opened', 'tiny_authory_tech'),
                getString('due', 'tiny_authory_tech'),
                getString('overdue', 'tiny_authory_tech'),
                getString('remaining', 'tiny_authory_tech'),
                getString('savechanges', 'tiny_authory_tech'),
                getString('subjectnot', 'tiny_authory_tech'),
                getString('remaining', 'tiny_authory_tech'),
            ]).then(function(strings) {
                return localStorage.setItem('docSideBar', JSON.stringify(strings));
            }).catch(error => window.console.error(error));
        }
    }

    /**
     * @returns {void}
     */
    function checkIsPdfAnnotator() {
        if (ur.includes('pdfannotator')) {
            if (editor.id !== 'id_pdfannotator_content' && parseInt(localStorage.getItem('isEditing'))) {
                resourceId = parseInt(editor?.id.replace('editarea', ''));
            } else {
                resourceId = 0;
            }
        }
    }

    window.addEventListener('unload', () => {
        syncData();
    });

    let _syncFailures = 0;
    /**
     * @async
     * @returns {Promise<void>}
     */
    async function scheduledSync() {
        const ok = await syncData();
        _syncFailures = ok ? 0 : _syncFailures + 1;
        const delay = _syncFailures > 0
            ? Math.min(syncInterval * (2 ** Math.min(_syncFailures, 5)), 300000)
            : syncInterval;
        setTimeout(scheduledSync, delay);
    }
    setTimeout(scheduledSync, syncInterval);

    customTooltip();
};
