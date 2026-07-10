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
 * @module     tiny_authory_tech/replay
 * @category TinyMCE Editor
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author kuldeep singh <mca.kuldeep.sekhon@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchJson} from 'core/ajax';
import templates from 'core/templates';
import $ from 'jquery';
import * as Str from 'core/str';

export default class Replay {
    constructor(elementId, filePath, speed = 1, loop = false, controllerId) {
        // Initialize core properties
        this.controllerId = controllerId || '';
        this.replayInProgress = false;
        this.speed = parseFloat(speed);
        this.loop = loop;
        this.highlightedChars = [];
        this.deletedChars = [];
        this.cursorPosition = 0;
        this.currentEventIndex = 0;
        this.totalEvents = 0;
        this.currentTime = 0;
        this.totalDuration = 0;
        this.windowClosedMs = 300000;
        this.progressFill = null;
        this.thumbIndicator = null;
        this.usercomments = [];
        this.pasteTimestamps = [];
        this.breakPositions = [];
        this.shownBreakIndices = new Set();
        this.seekCheckpoints = [];
        this.nextTickAt = null;
        this._docMouseMove = null;
        this._docMouseUp = null;
        this.isPasteEvent = false;
        this.isControlKeyPressed = false;
        this.isShiftKeyPressed = false;
        this.isMetaKeyPressed = false;
        this.isAltKeyPressed = false;
        this.text = '';
        this.pastedEvents = [];
        this.currentPasteIndex = 0;
        this.pastedChars = [];
        this.aiEvents = [];
        this.currentAiIndex = 0;
        this.aiChars = [];
        this.undoTimestamps = [];
        this.undoChars = [];

        const element = document.getElementById(elementId);
        if (!element) {
            throw new Error(`Element with id '${elementId}' not found`);
        }
        this.outputElement = element;

        this.annotations = new Map();
        this.serverAnalytics = null;
        this.segments = [];

        // Fetch annotations from the server in parallel — non-blocking.
        this.fetchAnnotations(filePath).then(ann => {
            if (ann && ann.ai_annotations) {
                for (const a of ann.ai_annotations) {
                    this.annotations.set(a.t, a);
                }
            }
            if (ann && ann.analytics) {
                this.serverAnalytics = ann.analytics;
            }
            if (ann && ann.segments) {
                this.segments = ann.segments;
                this.renderSegmentOverlay();
            }
            if (ann && ann.window_closed_ms) {
                this.windowClosedMs = ann.window_closed_ms;
            }
        }).catch(error => window.console.error('Error fetching replay annotations:', error));

        // Load JSON data and initialize replay
        this.loadJSON(filePath).then(data => {
            if (data.status) {
                this.processData(data);
                this.totalEvents = this.logData.length;
                this.identifyPasteEvents();
                this.identifyUndoEvents();
                if (this.controllerId && this.logData) {
                    this.constructController(this.controllerId);
                }
                this.startReplay();
            } else {
                this.handleNoSubmission();
            }
            return data;
        }).catch(error => {
            this.handleNoSubmission();
            window.console.error('Error loading JSON file:', error.message);
        });
        if (!localStorage.getItem('nopasteevent') || !localStorage.getItem('pasteEvent')) {
            Str.get_string('nopasteevent', 'tiny_authory_tech').then(str => {
                localStorage.setItem('nopasteevent', str);
                return str;
            }).catch(error => window.console.log(error));
            Str.get_string('pasteEvent', 'tiny_authory_tech').then(str => {
                localStorage.setItem('pasteEvent', str);
                return str;
            }).catch(error => window.console.log(error));
        }
    }

    // Process JSON data and normalize timestamps
    processData(data) {
        this.logData = JSON.parse(data.data);
        if (data.comments) {
            this.usercomments = Array.isArray(JSON.parse(data.comments)) ? JSON.parse(data.comments) : [];
        }
        if ('data' in this.logData) {
            this.logData = this.logData.data;
        }
        if ('payload' in this.logData) {
            this.logData = this.logData.payload;
        }

        // Collect paste and AI events for the events panel
        for (let i = 0; i < this.logData.length; i++) {
            const ev = this.logData[i];
            if (ev.meta === 'paste') {
                const insertOp = (ev.d || []).find(op => op.insert);
                if (insertOp && insertOp.insert.trim()) {
                    this.pastedEvents.push(insertOp.insert);
                }
            }
            if (ev.meta === 'autoinsert') {
                const insertOp = (ev.d || []).find(op => op.insert);
                if (insertOp) {
                    this.aiEvents.push(insertOp.insert);
                }
            }
        }

        if (this.logData.length > 0 && this.logData[0].t) {
            const startTime = this.logData[0].t;
            this.logData = this.logData.map(ev => ({
                ...ev,
                normalizedTime: ev.t - startTime,
            }));
            this.totalDuration = this.logData[this.logData.length - 1].normalizedTime;
        }
    }

    async handleNoSubmission() {
        try {
            const [html, str] = await Promise.all([
                templates.render('tiny_authory_tech/no_submission'),
                Str.get_string('warningpayload', 'tiny_authory_tech')
            ]);
            const newElement = $(html).text(str);
            return $('.tiny_authory_tech').html(newElement);
        } catch (error) {
            window.console.error(error);
            return false;
        }
    }

    // Stop the replay and update play button icon
    stopReplay(natural = false) {
        if (this.replayInProgress) {
            clearTimeout(this.replayTimeout);
            this.replayInProgress = false;
            this.hideAwayOverlay();
            if (this.playButton) {
                if (natural) {
                    this.playButton.querySelector('.play-icon').innerHTML =
                        '<span style="font-size:18px;line-height:1;">↺</span>';
                    this.playButton.title = 'Replay from start';
                    this.playButton.setAttribute('aria-label', 'Replay from start');
                } else {
                    const playSvg = document.createElement('img');
                    playSvg.src = M.util.image_url('playicon', 'tiny_authory_tech');
                    this.playButton.querySelector('.play-icon').innerHTML = playSvg.outerHTML;
                    this.playButton.title = '';
                    this.playButton.setAttribute('aria-label', 'Play');
                }
            }
        }
    }

    // Build the replay control UI (play button, scrubber, speed controls)
    constructController(controllerId) {
        this.replayInProgress = false;
        this.currentPosition = 0;
        this.speed = 1;
        if (this.replayIntervalId) {
            clearInterval(this.replayIntervalId);
            this.replayIntervalId = null;
        }

        const container = document.getElementById(controllerId);
        if (!container) {
            window.console.error('Container not found with ID:', controllerId);
            return;
        }

        // If the element itself is the control surface (assign_dashboard pattern),
        // use it directly. Otherwise look for a child (analytics_modal pattern).
        const controlContainer = container.classList.contains('tiny_authory_tech_replay_control')
            ? container
            : container.querySelector('.tiny_authory_tech_replay_control') ?? container;
        controlContainer.innerHTML = '<span class="tiny_authory_tech_loading_spinner"></span>';

        this.buildControllerUI(controlContainer, container);
        controlContainer.querySelector('.tiny_authory_tech_loading_spinner')?.remove();
        this.renderSegmentOverlay();
    }

    createLegend() {
        const legend = document.createElement('div');
        legend.setAttribute('aria-label', 'Segment legend');
        legend.style.cssText = 'display:flex;gap:10px;align-items:center;flex:1;' +
            'justify-content:center;font-size:11px;color:#666;font-family:"Lexend",sans-serif;';
        const items = [
            {type: 'dot', color: '#234fc2', label: 'Typing ✍️'},
            {type: 'dot', color: '#b86e00', label: 'Break ⏳'},
            {type: 'dot', color: '#8c7a6b', label: 'Away \u{1F4A4}'},
            {type: 'tick', color: '#e74c3c', emoji: '\u{1F4D1}', label: 'Paste'},
            {type: 'tick', color: 'rgba(124,92,191,0.65)', emoji: '↩', label: 'Undo'},
        ];
        for (const item of items) {
            const el = document.createElement('span');
            el.style.cssText = 'display:inline-flex;align-items:center;gap:4px;white-space:nowrap;';
            if (item.type === 'dot') {
                const dot = document.createElement('span');
                dot.setAttribute('aria-hidden', 'true');
                dot.style.cssText = 'width:8px;height:8px;border-radius:50%;flex-shrink:0;' +
                    'background:' + item.color + ';display:inline-block;';
                el.appendChild(dot);
            } else if (item.type === 'tick') {
                const tick = document.createElement('span');
                tick.setAttribute('aria-hidden', 'true');
                tick.style.cssText = 'width:2px;height:11px;background:' + item.color + ';' +
                    'display:inline-block;border-radius:1px;flex-shrink:0;';
                const eIcon = document.createElement('span');
                eIcon.setAttribute('aria-hidden', 'true');
                eIcon.textContent = item.emoji;
                eIcon.style.cssText = 'font-size:10px;line-height:1;';
                el.appendChild(tick);
                el.appendChild(eIcon);
            } else {
                const eIcon = document.createElement('span');
                eIcon.setAttribute('aria-hidden', 'true');
                eIcon.textContent = item.emoji;
                eIcon.style.cssText = 'font-size:11px;line-height:1;';
                el.appendChild(eIcon);
            }
            const lbl = document.createElement('span');
            lbl.textContent = item.label;
            el.appendChild(lbl);
            legend.appendChild(el);
        }
        return legend;
    }

    buildControllerUI(controlContainer, container) {
        const topRow = document.createElement('div');
        topRow.classList.add('tiny_authory_tech_top_row');

        this.playButton = this.createPlayButton();
        topRow.appendChild(this.playButton);

        const scrubberContainer = this.createScrubberContainer();
        topRow.appendChild(scrubberContainer);

        this.timeDisplay = this.createTimeDisplay();
        topRow.appendChild(this.timeDisplay);

        const bottomRow = document.createElement('div');
        bottomRow.classList.add('tiny_authory_tech_bottom_row');

        const speedContainer = this.createSpeedControls();
        bottomRow.appendChild(speedContainer);

        bottomRow.appendChild(this.createLegend());

        const pasteEventsToggle = this.createPasteEventsToggle(container);
        bottomRow.appendChild(pasteEventsToggle);

        controlContainer.appendChild(topRow);
        controlContainer.appendChild(bottomRow);
        container.appendChild(this.pasteEventsPanel);
    }

    createPlayButton() {
        const playButton = document.createElement('button');
        playButton.classList.add('tiny_authory_tech_play_button');
        const playSvg = document.createElement('i');
        playButton.innerHTML = `<span class="play-icon">${playSvg.outerHTML}</span>`;
        playButton.addEventListener('click', () => {
            if (this.replayInProgress) {
                this.stopReplay();
            } else {
                this.startReplay(false);
            }
            $('.tiny_authory_tech-nav-tab').find('.active').removeClass('active');
            $('a[id^="rep"]').addClass('active');
        });
        return playButton;
    }

    createScrubberContainer() {
        const scrubberContainer = document.createElement('div');
        scrubberContainer.classList.add('tiny_authory_tech_scrubber_container');
        scrubberContainer.style.cssText = 'position:relative;display:flex;align-items:center;' +
            'flex:1;height:32px;cursor:pointer;';
        scrubberContainer.setAttribute('role', 'slider');
        scrubberContainer.setAttribute('tabindex', '0');
        scrubberContainer.setAttribute('aria-label', 'Replay timeline');
        scrubberContainer.setAttribute('aria-valuemin', '0');
        scrubberContainer.setAttribute('aria-valuemax', '100');
        scrubberContainer.setAttribute('aria-valuenow', '0');

        const barPctFromClientX = (clientX) => {
            const rect = scrubberContainer.getBoundingClientRect();
            return Math.max(0, Math.min(100, ((clientX - rect.left) / rect.width) * 100));
        };

        const seekTo = (clientX) => {
            const barPct = barPctFromClientX(clientX);
            const normTime = this.barPctToNormTime(barPct);
            this.skipToTime((normTime / (this.totalDuration || 1)) * 100);
        };

        let dragging = false;

        scrubberContainer.addEventListener('mousedown', (e) => {
            dragging = true;
            seekTo(e.clientX);
            e.preventDefault();
        });
        this._docMouseMove = (e) => { if (dragging) { seekTo(e.clientX); } };
        this._docMouseUp = () => { dragging = false; };
        document.addEventListener('mousemove', this._docMouseMove);
        document.addEventListener('mouseup', this._docMouseUp);

        scrubberContainer.addEventListener('touchstart', (e) => {
            dragging = true;
            seekTo(e.touches[0].clientX);
            e.preventDefault();
        }, {passive: false});
        scrubberContainer.addEventListener('touchmove', (e) => {
            if (dragging) { seekTo(e.touches[0].clientX); }
            e.preventDefault();
        }, {passive: false});
        scrubberContainer.addEventListener('touchend', () => { dragging = false; });

        scrubberContainer.addEventListener('keydown', (e) => {
            const step = e.shiftKey ? 10 : 2;
            const cur = parseFloat(scrubberContainer.getAttribute('aria-valuenow') || '0');
            if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
                const barPct = Math.min(100, cur + step);
                this.skipToTime((this.barPctToNormTime(barPct) / (this.totalDuration || 1)) * 100);
                e.preventDefault();
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
                const barPct = Math.max(0, cur - step);
                this.skipToTime((this.barPctToNormTime(barPct) / (this.totalDuration || 1)) * 100);
                e.preventDefault();
            }
        });

        this.scrubberContainer = scrubberContainer;
        this.setupTrackTooltip();
        return scrubberContainer;
    }

    setupTrackTooltip() {
        const tip = document.createElement('div');
        tip.className = 'tiny_authory_tech_track_tip';
        tip.style.cssText = 'position:absolute;bottom:calc(100% + 8px);' +
            'background:#1a3a8f;color:#fbf2e3;border-radius:6px;padding:4px 10px;' +
            'font-size:11px;font-family:"Lexend",sans-serif;white-space:nowrap;' +
            'pointer-events:none;z-index:10;display:none;transform:translateX(-50%);' +
            'box-shadow:0 2px 8px rgba(26,58,143,0.35);';
        this.scrubberContainer.appendChild(tip);

        this.scrubberContainer.addEventListener('mousemove', (e) => {
            if (!this.totalDuration) {
                tip.style.display = 'none';
                return;
            }
            const rect = this.scrubberContainer.getBoundingClientRect();
            const cursorX = e.clientX - rect.left;
            const pct = Math.max(0, Math.min(1, cursorX / rect.width));

            // Paste markers take priority — check within 10px of each icon.
            for (const paste of this.pasteTimestamps) {
                const pasteX = (paste.normalizedTime / this.totalDuration) * rect.width;
                if (Math.abs(cursorX - pasteX) <= 10) {
                    const raw = paste.pastedText || '';
                    const preview = raw.length > 40 ? raw.slice(0, 40) + '…' : raw;
                    const pasteLabel = '\u{1F4D1} Paste at ' + paste.formattedTime;
                    tip.textContent = preview ? pasteLabel + ' — "' + preview + '"' : pasteLabel;
                    tip.style.left = (paste.normalizedTime / this.totalDuration * 100) + '%';
                    tip.style.display = 'block';
                    return;
                }
            }

            // Segment tooltip — walk logData gaps (same logic as band rendering).
            if (!this.logData || this.logData.length < 2 || !this.totalDuration) {
                tip.style.display = 'none';
                return;
            }
            const inactMs = 5000;
            const hovered = this.barPctToNormTime(pct * 100);
            let label = '';
            let durMs = 0;
            let bandStart = 0;
            let bState = null;

            // Check if cursor is inside a break slot.
            const tipN = this.breakPositions.length;
            const tipSlot = tipN > 0 ? Math.min(3, 60 / tipN) : 0;
            for (const bp of this.breakPositions) {
                const slotL = this.normTimeToBarPct(bp.at);
                if (pct * 100 >= slotL && pct * 100 <= slotL + tipSlot) {
                    label = 'Away';
                    durMs = bp.duration;
                    break;
                }
            }

            if (!label) {
                for (let i = 1; i < this.logData.length; i++) {
                    const ev = this.logData[i];
                    const prev = this.logData[i - 1];
                    const gap = ev.t - prev.t;
                    if (gap >= this.windowClosedMs) {
                        if (bState !== null && hovered >= bandStart && hovered < prev.normalizedTime) {
                            label = bState === 'writing' ? 'Typing' : 'Break';
                            durMs = prev.normalizedTime - bandStart;
                        }
                        bState = null;
                        bandStart = ev.normalizedTime;
                        if (label) { break; }
                        continue;
                    }
                    const st = gap < inactMs ? 'writing' : 'inactive';
                    if (bState === null) {
                        bState = st;
                        bandStart = prev.normalizedTime;
                    } else if (st !== bState) {
                        if (hovered >= bandStart && hovered < prev.normalizedTime) {
                            label = bState === 'writing' ? 'Typing' : 'Break';
                            durMs = prev.normalizedTime - bandStart;
                            break;
                        }
                        bState = st;
                        bandStart = prev.normalizedTime;
                    }
                    if (i === this.logData.length - 1 && !label) {
                        const end = ev.normalizedTime;
                        if (hovered >= bandStart && hovered <= end) {
                            label = bState === 'writing' ? 'Typing' : 'Break';
                            durMs = end - bandStart;
                        }
                    }
                }
            }

            if (!label) {
                tip.style.display = 'none';
                return;
            }
            const m = Math.floor(durMs / 60000);
            const secs = Math.round((durMs % 60000) / 1000);
            tip.textContent = label + ' — ' + (m > 0 ? m + 'm ' + secs + 's' : secs + 's');
            tip.style.left = (pct * 100) + '%';
            tip.style.display = 'block';
        });

        this.scrubberContainer.addEventListener('mouseleave', () => {
            tip.style.display = 'none';
        });
    }

    // Map compressed time → visual bar percentage.
    // Active content fills proportionally; each window_closed break gets a fixed 3% slot.
    normTimeToBarPct(t) {
        if (!this.totalDuration) { return 0; }
        const N = this.breakPositions.length;
        if (!N) { return (t / this.totalDuration) * 100; }
        const slotPct = Math.min(3, 60 / N);
        const activePct = 100 - N * slotPct;
        const nBefore = this.breakPositions.filter(bp => bp.at < t).length;
        return (t / this.totalDuration) * activePct + nBefore * slotPct;
    }

    // Inverse: map visual bar percentage → compressed time (for scrubber seeking).
    barPctToNormTime(barPct) {
        if (!this.totalDuration) { return 0; }
        const N = this.breakPositions.length;
        if (!N) { return (barPct / 100) * this.totalDuration; }
        const slotPct = Math.min(3, 60 / N);
        const activePct = 100 - N * slotPct;
        let pos = 0;
        let prevAt = 0;
        for (let i = 0; i <= N; i++) {
            const bp = i < N ? this.breakPositions[i] : null;
            const nextAt = bp ? bp.at : this.totalDuration;
            const segActiveDur = nextAt - prevAt;
            const segActivePct = (segActiveDur / this.totalDuration) * activePct;
            if (barPct <= pos + segActivePct) {
                const rel = segActivePct > 0 ? (barPct - pos) / segActivePct : 0;
                return prevAt + rel * segActiveDur;
            }
            pos += segActivePct;
            if (bp) {
                if (barPct < pos + slotPct) { return bp.at; }
                pos += slotPct;
                prevAt = bp.at;
            }
        }
        return this.totalDuration;
    }

    // Recompute normalizedTime for every event, collapsing window_closed gaps so
    // they take zero replay time. Updates this.totalDuration accordingly.
    // Safe to call multiple times (always reads from the original ev.t values).
    compressTimeline() {
        if (!this.logData || !this.logData.length || !this.segments.length) {
            return;
        }
        const windowClosedMs = this.windowClosedMs;
        this.breakPositions = [];
        let compressed = 0;
        for (let i = 0; i < this.logData.length; i++) {
            if (i > 0) {
                const gap = this.logData[i].t - this.logData[i - 1].t;
                if (gap < windowClosedMs) {
                    compressed += gap;
                } else {
                    this.breakPositions.push({at: compressed, duration: gap});
                }
            }
            this.logData[i] = {...this.logData[i], normalizedTime: compressed};
        }
        this.totalDuration = compressed;
    }

    // Two-layer scrubber:
    //   track   — overflow:hidden so segment bands clip to rounded ends
    //   overlay — overflow:visible so break/paste icons float above
    renderSegmentOverlay() {
        if (!this.scrubberContainer || !this.logData || !this.logData.length) {
            return;
        }

        if (this.segments.length) {
            this.compressTimeline();
        } else {
            const first = this.logData[0].t;
            this.totalDuration = this.logData[this.logData.length - 1].t - first;
            for (let i = 0; i < this.logData.length; i++) {
                this.logData[i] = {...this.logData[i], normalizedTime: this.logData[i].t - first};
            }
        }

        this.identifyPasteEvents();
        this.identifyUndoEvents();

        const totalDuration = this.totalDuration;
        if (totalDuration <= 0) {
            return;
        }

        const existingTrack = this.scrubberContainer.querySelector('.tiny_authory_tech_segment_track');
        if (existingTrack) { existingTrack.remove(); }
        const existingOverlay = this.scrubberContainer.querySelector('.tiny_authory_tech_marker_overlay');
        if (existingOverlay) { existingOverlay.remove(); }

        const stateColors = {writing: '#234fc2', inactive: '#b86e00', away: '#8c7a6b'};

        const fmtDur = ms => {
            const s = Math.round(ms / 1000);
            const m = Math.floor(s / 60);
            return m > 0 ? `${m}m ${s % 60}s` : `${s}s`;
        };

        // ── Track (overflow:hidden — bands clip to rounded ends) ─────────────
        const track = document.createElement('div');
        track.classList.add('tiny_authory_tech_segment_track');
        track.style.cssText = 'position:absolute;top:50%;left:0;transform:translateY(-50%);' +
            'height:8px;width:100%;border-radius:4px;overflow:hidden;background:#e8e8e8;z-index:1;';

        // Build colored bands from logData gaps; positions use normTimeToBarPct so
        // break slots get their fixed visual allocation.
        const inactiveMs = 5000;
        const N = this.breakPositions.length;
        const slotPct = N > 0 ? Math.min(3, 60 / N) : 0;
        let bandStartNorm = 0;
        let bandState = null;
        // After a break slot, the next band must start at brkLeft+slotPct, not at
        // normTimeToBarPct(ev.normalizedTime) — both sides of a break share the same
        // compressed time value, so the coordinate function returns the same position.
        let bandStartPct = null;
        const bands = [];

        const flushBand = endNorm => {
            if (bandState === null || endNorm <= bandStartNorm) { return; }
            const dur = endNorm - bandStartNorm;
            if (dur < 10) { return; }
            const left = bandStartPct !== null ? bandStartPct : this.normTimeToBarPct(bandStartNorm);
            bandStartPct = null;
            const width = this.normTimeToBarPct(endNorm) - left;
            if (width <= 0) { return; }
            const label = bandState === 'writing' ? 'Typing' : 'Break';
            const span = document.createElement('div');
            span.style.cssText = `position:absolute;top:0;height:100%;left:${left.toFixed(2)}%;` +
                `width:${width.toFixed(2)}%;background:${stateColors[bandState]};`;
            span.setAttribute('role', 'img');
            span.setAttribute('aria-label', `${label} — ${fmtDur(dur)}`);
            span.title = `${label} — ${fmtDur(dur)}`;
            track.appendChild(span);
            bands.push({state: bandState, left, width});
        };

        for (let i = 1; i < this.logData.length; i++) {
            const ev = this.logData[i];
            const prev = this.logData[i - 1];
            const gap = ev.t - prev.t;
            if (gap >= this.windowClosedMs) {
                flushBand(prev.normalizedTime);
                const brkLeft = this.normTimeToBarPct(prev.normalizedTime);
                const brkDiv = document.createElement('div');
                brkDiv.setAttribute('role', 'img');
                brkDiv.setAttribute('aria-label', `Away — ${fmtDur(gap)}`);
                brkDiv.style.cssText = `position:absolute;top:0;height:100%;left:${brkLeft.toFixed(2)}%;` +
                    `width:${slotPct.toFixed(2)}%;background:${stateColors.away};`;
                track.appendChild(brkDiv);
                bandState = null;
                bandStartNorm = ev.normalizedTime;
                bandStartPct = brkLeft + slotPct;
                continue;
            }
            const st = gap < inactiveMs ? 'writing' : 'inactive';
            if (bandState === null) {
                bandState = st;
                bandStartNorm = prev.normalizedTime;
            } else if (st !== bandState) {
                flushBand(prev.normalizedTime);
                bandState = st;
                bandStartNorm = prev.normalizedTime;
            }
        }
        if (bandState !== null && this.logData.length > 0) {
            flushBand(this.logData[this.logData.length - 1].normalizedTime);
        }

        this.progressFill = document.createElement('div');
        this.progressFill.style.cssText = 'position:absolute;top:0;left:0;height:100%;width:0%;' +
            'background:rgba(0,0,0,0.12);pointer-events:none;z-index:2;';
        track.appendChild(this.progressFill);

        // ── Marker overlay (overflow:visible — icons float above track) ───────
        const overlay = document.createElement('div');
        overlay.classList.add('tiny_authory_tech_marker_overlay');
        overlay.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;' +
            'pointer-events:none;z-index:3;overflow:visible;';

        // 💤 label above each break slot — sits just above the track, like paste markers.
        for (const bp of this.breakPositions) {
            const midPct = this.normTimeToBarPct(bp.at) + slotPct / 2;
            const brk = document.createElement('div');
            brk.setAttribute('aria-hidden', 'true');
            brk.title = `Away — ${fmtDur(bp.duration)}`;
            brk.style.cssText = `position:absolute;bottom:calc(50% + 6px);left:${midPct.toFixed(2)}%;` +
                'transform:translateX(-50%);font-size:10px;line-height:1;' +
                'pointer-events:none;z-index:4;';
            brk.textContent = '\u{1F4A4}';
            overlay.appendChild(brk);
        }

        for (const paste of this.pasteTimestamps) {
            const left = this.normTimeToBarPct(paste.normalizedTime);
            const wrap = document.createElement('div');
            wrap.title = `Paste — ${paste.formattedTime}`;
            wrap.style.cssText = `position:absolute;top:50%;left:${left.toFixed(2)}%;` +
                'transform:translate(-50%,-50%);width:12px;pointer-events:none;';

            const pasteIcon = document.createElement('div');
            pasteIcon.setAttribute('aria-hidden', 'true');
            pasteIcon.textContent = '\u{1F4D1}';
            pasteIcon.style.cssText = 'position:absolute;bottom:calc(100% + 3px);left:50%;' +
                'transform:translateX(-50%);font-size:10px;line-height:1;';

            const pasteLine = document.createElement('div');
            pasteLine.style.cssText = 'position:absolute;top:50%;left:50%;' +
                'transform:translate(-50%,-50%);width:1.5px;height:16px;' +
                'background:#e74c3c;border-radius:1px;';

            wrap.appendChild(pasteIcon);
            wrap.appendChild(pasteLine);
            overlay.appendChild(wrap);
        }

        // ↩ undo tick markers — deduplicated (many rapid undos share a position).
        let lastUndoLeft = -5;
        for (const undo of this.undoTimestamps) {
            const left = this.normTimeToBarPct(undo.normalizedTime);
            if (left - lastUndoLeft < 1) { continue; }
            lastUndoLeft = left;
            const wrap = document.createElement('div');
            wrap.title = `Undo — ${undo.formattedTime}`;
            wrap.style.cssText = `position:absolute;top:50%;left:${left.toFixed(2)}%;` +
                'transform:translate(-50%,-50%);width:8px;pointer-events:none;';
            const line = document.createElement('div');
            line.setAttribute('aria-hidden', 'true');
            line.style.cssText = 'position:absolute;top:50%;left:50%;' +
                'transform:translate(-50%,-50%);width:1px;height:12px;' +
                'background:rgba(124,92,191,0.65);border-radius:1px;';
            const icon = document.createElement('div');
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '↩';
            icon.style.cssText = 'position:absolute;bottom:calc(100% + 2px);left:50%;' +
                'transform:translateX(-50%);font-size:8px;line-height:1;' +
                'color:rgba(124,92,191,0.85);';
            wrap.appendChild(icon);
            wrap.appendChild(line);
            overlay.appendChild(wrap);
        }

        // ✍️ / ⏳ icons centered above each band (only when band is wide enough).
        for (const band of bands) {
            if (band.width < 3) { continue; }
            const midPct = band.left + band.width / 2;
            const icon = document.createElement('div');
            icon.setAttribute('aria-hidden', 'true');
            icon.style.cssText = `position:absolute;bottom:calc(50% + 6px);left:${midPct.toFixed(2)}%;` +
                'transform:translateX(-50%);font-size:10px;line-height:1;pointer-events:none;z-index:4;';
            icon.textContent = band.state === 'writing' ? '✍️' : '⏳';
            overlay.appendChild(icon);
        }

        this.thumbIndicator = document.createElement('div');
        this.thumbIndicator.style.cssText = 'position:absolute;top:50%;left:0%;' +
            'transform:translate(-50%,-50%);width:12px;height:12px;border-radius:50%;' +
            'background:#fff;border:2px solid #234fc2;' +
            'box-shadow:0 1px 4px rgba(35,79,194,0.4);z-index:4;';
        overlay.appendChild(this.thumbIndicator);

        this.scrubberContainer.appendChild(track);
        this.scrubberContainer.appendChild(overlay);
        this.buildSeekCheckpoints();
    }

    createTimeDisplay() {
        const timeDisplay = document.createElement('div');
        timeDisplay.classList.add('tiny_authory_tech_time_display');
        timeDisplay.textContent = '00:00 / 00:00';
        return timeDisplay;
    }

    createSpeedControls() {
        const speedContainer = document.createElement('div');
        speedContainer.classList.add('tiny_authory_tech_speed_controls', 'speed-controls');
        const speedLabel = document.createElement('span');
        speedLabel.classList.add('tiny_authory_tech_speed_label');
        speedLabel.textContent = 'Speed: ';
        speedContainer.appendChild(speedLabel);

        const speedGroup = document.createElement('div');
        speedGroup.classList.add('tiny_authory_tech_speed_group');
        [1, 1.5, 2, 5, 10].forEach(speed => {
            const speedBtn = document.createElement('button');
            speedBtn.textContent = `${speed}x`;
            speedBtn.classList.add('tiny_authory_tech_speed_btn', 'speed-btn');
            if (parseFloat(speed) === this.speed) {
                speedBtn.classList.add('active');
            }
            speedBtn.dataset.speed = speed;
            speedBtn.addEventListener('click', () => {
                document.querySelectorAll('.tiny_authory_tech_speed_btn').forEach(btn => btn.classList.remove('active'));
                speedBtn.classList.add('active');
                this.speed = parseFloat(speedBtn.dataset.speed);
                if (this.replayInProgress) {
                    this.stopReplay();
                    this.startReplay(false);
                }
            });
            speedGroup.appendChild(speedBtn);
        });
        speedContainer.appendChild(speedGroup);
        return speedContainer;
    }

    createPasteEventsToggle(container) {
        const pasteEventsToggle = document.createElement('div');
        pasteEventsToggle.classList.add('tiny_authory_tech_paste_events_toggle', 'paste-events-toggle');

        const pasteEventsIcon = document.createElement('span');
        const pasteIcon = document.createElement('img');
        pasteIcon.src = M.util.image_url('pasteicon', 'tiny_authory_tech');
        pasteEventsIcon.innerHTML = pasteIcon.outerHTML;
        pasteEventsIcon.classList.add('tiny_authory_tech_paste_events_icon');

        const pasteEventsText = document.createElement('span');
        pasteEventsText.textContent = localStorage.getItem('pasteEvent');

        this.pasteEventCount = document.createElement('span');
        this.pasteEventCount.textContent = `(${this.pasteTimestamps.length})`;
        this.pasteEventCount.className = 'paste-event-count';
        this.pasteEventCount.style.marginLeft = '2px';

        const chevronIcon = document.createElement('span');
        const chevron = document.createElement('i');
        chevron.className = 'fa fa-chevron-down';
        chevronIcon.innerHTML = chevron.outerHTML;
        chevronIcon.style.marginLeft = '5px';
        chevronIcon.style.transition = 'transform 0.3s ease';

        pasteEventsToggle.appendChild(pasteEventsIcon);
        pasteEventsToggle.appendChild(pasteEventsText);
        pasteEventsToggle.appendChild(this.pasteEventCount);
        pasteEventsToggle.appendChild(chevronIcon);

        this.pasteEventsPanel = this.createPasteEventsPanel(container);
        pasteEventsToggle.addEventListener('click', () => {
            const isHidden = this.pasteEventsPanel.style.display === 'none';
            this.pasteEventsPanel.style.display = isHidden ? 'block' : 'none';
            chevronIcon.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
        });

        return pasteEventsToggle;
    }

    createPasteEventsPanel(container) {
        const existingPanel = container.querySelector('.paste-events-panel');
        if (existingPanel) {
            existingPanel.remove();
        }
        const pasteEventsPanel = document.createElement('div');
        pasteEventsPanel.classList.add('tiny_authory_tech_paste_events_panel', 'paste-events-panel');
        pasteEventsPanel.style.display = 'none';
        this.populatePasteEventsPanel(pasteEventsPanel);
        return pasteEventsPanel;
    }

    // Build paste and undo timestamp lists used by the events panel and scrubber
    identifyPasteEvents() {
        this.pasteTimestamps = [];
        let pasteCount = 0;
        for (let i = 0; i < this.logData.length; i++) {
            const ev = this.logData[i];
            if (ev.meta === 'paste' && this.pastedEvents[pasteCount]) {
                const timestamp = ev.normalizedTime || 0;
                this.pasteTimestamps.push({
                    index: pasteCount,
                    normalizedTime: timestamp,
                    time: timestamp,
                    formattedTime: this.formatTime(timestamp),
                    pastedText: this.pastedEvents[pasteCount],
                    timestamp,
                });
                pasteCount++;
            }
        }
        if (this.pasteEventsPanel) {
            this.populatePasteEventsPanel(this.pasteEventsPanel);
        }
    }

    identifyUndoEvents() {
        this.undoTimestamps = [];
        let undoCount = 0;
        for (let i = 0; i < this.logData.length; i++) {
            const ev = this.logData[i];
            if (ev.meta === 'undo') {
                const timestamp = ev.normalizedTime || 0;
                this.undoTimestamps.push({
                    index: undoCount,
                    normalizedTime: timestamp,
                    time: timestamp,
                    formattedTime: this.formatTime(timestamp),
                    timestamp,
                });
                undoCount++;
            }
        }
    }

    // Populate the paste events panel with navigation
    populatePasteEventsPanel(panel) {
        panel.innerHTML = '';
        panel.classList.add('tiny_authory_tech_event_panel');

        if (!this.pasteTimestamps.length) {
            const noEventsMessage = document.createElement('div');
            noEventsMessage.className = 'no-paste-events-message p-3';
            noEventsMessage.textContent = localStorage.getItem('nopasteevent');
            panel.appendChild(noEventsMessage);
            return;
        }

        const carouselContainer = document.createElement('div');
        carouselContainer.classList.add('tiny_authory_tech_paste_events_carousel', 'paste-events-carousel');

        const navigationRow = document.createElement('div');
        navigationRow.classList.add('paste-events-navigation', 'tiny_authory_tech_navigation_row');

        const counterDisplay = document.createElement('div');
        counterDisplay.classList.add('paste-events-counter', 'tiny_authory_tech_counter_display');
        counterDisplay.textContent = 'Paste Events';

        const navButtons = document.createElement('div');
        navButtons.classList.add('tiny_authory_tech_nav_buttons');
        const prevButton = document.createElement('button');
        prevButton.classList.add('paste-event-prev-btn', 'tiny_authory_tech_nav_button');
        prevButton.innerHTML = '<i class="fa fa-chevron-left"></i>';

        const nextButton = document.createElement('button');
        nextButton.classList.add('paste-event-next-btn', 'tiny_authory_tech_nav_button');
        nextButton.innerHTML = '<i class="fa fa-chevron-right"></i>';
        nextButton.disabled = this.pasteTimestamps.length <= 1;

        navButtons.appendChild(prevButton);
        navButtons.appendChild(nextButton);
        navigationRow.appendChild(counterDisplay);
        navigationRow.appendChild(navButtons);

        const contentContainer = document.createElement('div');
        contentContainer.className = 'paste-events-content tiny_authory_tech_content_container';
        contentContainer.appendChild(this.createPasteEventDisplay(this.pasteTimestamps[0]));

        carouselContainer.appendChild(navigationRow);
        carouselContainer.appendChild(contentContainer);
        panel.appendChild(carouselContainer);

        let currentIndex = 0;
        const updateDisplay = () => {
            contentContainer.innerHTML = '';
            contentContainer.appendChild(this.createPasteEventDisplay(this.pasteTimestamps[currentIndex]));
            counterDisplay.textContent = 'Paste Events';
            prevButton.disabled = currentIndex === 0;
            prevButton.style.opacity = currentIndex === 0 ? '0.5' : '1';
            nextButton.disabled = currentIndex === this.pasteTimestamps.length - 1;
            nextButton.style.opacity = currentIndex === this.pasteTimestamps.length - 1 ? '0.5' : '1';
        };

        prevButton.addEventListener('click', () => {
            if (currentIndex > 0) {
                currentIndex--;
                updateDisplay();
            }
        });

        nextButton.addEventListener('click', () => {
            if (currentIndex < this.pasteTimestamps.length - 1) {
                currentIndex++;
                updateDisplay();
            }
        });
    }

    createPasteEventDisplay(pasteEvent) {
        const eventRow = document.createElement('div');
        eventRow.className = 'tiny_authory_tech_event_row';

        const headerRow = document.createElement('div');
        headerRow.className = 'tiny_authory_tech_header_row';

        const textContainer = document.createElement('div');
        textContainer.className = 'tiny_authory_tech_text_container';

        const timestampContainer = document.createElement('div');
        timestampContainer.className = 'paste-event-timestamp tiny_authory_tech_paste_event_timestamp';
        timestampContainer.textContent = pasteEvent.formattedTime;

        const pastedTextContainer = document.createElement('div');
        pastedTextContainer.className = 'paste-event-text tiny_authory_tech_pasted_text_container';
        pastedTextContainer.textContent = pasteEvent.pastedText;

        textContainer.appendChild(timestampContainer);
        textContainer.appendChild(pastedTextContainer);

        const playButton = document.createElement('button');
        playButton.className = 'paste-event-play-btn tiny_authory_tech_seekplay_button';
        const playIcon = document.createElement('img');
        playIcon.src = M.util.image_url('seekplayicon', 'tiny_authory_tech');
        playButton.innerHTML = playIcon.outerHTML;
        playButton.addEventListener('click', () => this.jumpToTimestamp(pasteEvent.timestamp));

        headerRow.appendChild(textContainer);
        headerRow.appendChild(playButton);
        eventRow.appendChild(headerRow);

        return eventRow;
    }

    // Jump to a specific timestamp in the replay
    jumpToTimestamp(timestamp) {
        const percentage = this.totalDuration > 0 ? (timestamp / this.totalDuration) * 100 : 0;
        this.skipToTime(percentage); // skipToTime takes linear %, barPct conversion is in input handler
        if (!this.replayInProgress) {
            this.startReplay(false);
        }
    }

    setScrubberVal(value) {
        if (this.scrubberContainer) {
            this.scrubberContainer.setAttribute('aria-valuenow', String(Math.round(value)));
        }
        if (this.timeDisplay) {
            const displayTime = Math.min(this.currentTime, this.totalDuration);
            this.timeDisplay.textContent =
                `${this.formatTime(displayTime)} / ${this.formatTime(this.totalDuration)}`;
        }
        const barPct = this.normTimeToBarPct(this.currentTime);
        if (this.progressFill) {
            this.progressFill.style.width = `${barPct}%`;
        }
        if (this.thumbIndicator) {
            this.thumbIndicator.style.left = `${barPct}%`;
        }
    }

    loadJSON(filePath) {
        return fetchJson([{
            methodname: 'authory_tech_get_reply_json',
            args: {filepath: filePath}
        }])[0].done(response => response).fail(error => {
            throw new Error(`Error loading JSON file: ${error.message}`);
        });
    }

    fetchAnnotations(filePath) {
        return fetchJson([{
            methodname: 'authory_tech_get_replay_annotations',
            args: {filepath: filePath}
        }])[0].then(response => {
            try {
                return typeof response === 'string' ? JSON.parse(response) : response;
            } catch (e) {
                return null;
            }
        }).fail(() => null);
    }

    formatTime(ms) {
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
    }

    // Start or restart the replay
    startReplay(reset = true) {
        if (this.replayInProgress) {
            clearTimeout(this.replayTimeout);
        }
        const atEnd = (this.totalDuration > 0 && this.currentTime >= this.totalDuration) ||
            (this.currentEventIndex >= this.totalEvents);
        if (atEnd && !reset) {
            reset = true;
        }
        this.replayInProgress = true;
        if (this.playButton) {
            this.playButton.title = '';
            this.playButton.setAttribute('aria-label', 'Pause');
        }
        if (reset) {
            this.outputElement.innerHTML = '';
            this.text = '';
            this.cursorPosition = 0;
            this.currentEventIndex = 0;
            this.currentTime = 0;
            this.highlightedChars = [];
            this.deletedChars = [];
            this.isControlKeyPressed = false;
            this.isMetaKeyPressed = false;
            this.currentPasteIndex = 0;
            this.pastedChars = [];
            this.currentAiIndex = 0;
            this.aiChars = [];
            this.shownBreakIndices = new Set();
            this.nextTickAt = null;
            this.hideAwayOverlay();
        }
        if (this.playButton) {
            const pauseSvg = document.createElement('i');
            pauseSvg.className = 'fa fa-pause';
            this.playButton.querySelector('.play-icon').innerHTML = pauseSvg.outerHTML;
        }
        this.replayLog();
    }

    // Apply a Quill-style delta to text, returning the new text, cursor position,
    // list of inserted segments {pos, text}, and list of deleted segments {pos, content}.
    applyDelta(text, ops) {
        let result = '';
        let oldPos = 0;
        let newPos = 0;
        let cursor = this.cursorPosition;
        const inserts = [];
        const deletes = [];

        for (const op of ops) {
            if (op.retain) {
                result += text.slice(oldPos, oldPos + op.retain);
                oldPos += op.retain;
                newPos += op.retain;
            } else if (op.insert) {
                inserts.push({pos: newPos, text: op.insert});
                result += op.insert;
                newPos += op.insert.length;
                cursor = newPos;
            } else if (op.delete) {
                deletes.push({pos: newPos, content: text.slice(oldPos, oldPos + op.delete)});
                oldPos += op.delete;
            }
        }
        result += text.slice(oldPos);
        return {text: result, cursor, inserts, deletes};
    }

    // Shift all char-tracking arrays to account for an insertion or deletion
    // at a given position in the current text.
    shiftTrackingIndices(ops) {
        let newPos = 0;

        for (const op of ops) {
            if (op.retain) {
                newPos += op.retain;
            } else if (op.insert) {
                const insertPos = newPos;
                const insertLen = op.insert.length;
                const shift = h => { if (h.index >= insertPos) { h.index += insertLen; } };
                this.highlightedChars.forEach(shift);
                this.deletedChars.forEach(shift);
                this.pastedChars.forEach(shift);
                this.aiChars.forEach(shift);
                newPos += insertLen;
            } else if (op.delete) {
                const delPos = newPos;
                const delLen = op.delete;
                const keep = h => h.index < delPos || h.index >= delPos + delLen;
                const shiftBack = h => { if (h.index >= delPos + delLen) { h.index -= delLen; } };
                this.highlightedChars = this.highlightedChars.filter(keep);
                this.deletedChars = this.deletedChars.filter(keep);
                this.pastedChars = this.pastedChars.filter(keep);
                this.aiChars = this.aiChars.filter(keep);
                this.highlightedChars.forEach(shiftBack);
                this.deletedChars.forEach(shiftBack);
                this.pastedChars.forEach(shiftBack);
                this.aiChars.forEach(shiftBack);
                // newPos does not advance — deleted chars are not in new text
            }
        }
    }

    // Process events in sequence to reconstruct text from deltas
    replayLog() {
        if (!this.replayInProgress) {
            this.updateDisplayText(this.text, this.cursorPosition, [], []);
            return;
        }
        // Compensate for scheduling jitter: if the browser fired us late,
        // advance currentTime by the overrun so the replay stays in sync.
        const now = Date.now();
        if (this.nextTickAt !== null) {
            const overrun = now - this.nextTickAt;
            if (overrun > 0) {
                this.currentTime += Math.round(overrun * this.speed);
            }
        }

        while (this.currentEventIndex < this.logData.length) {
            const ev = this.logData[this.currentEventIndex];
            if (ev.normalizedTime && ev.normalizedTime > this.currentTime) {
                break;
            }

            const ops = ev.d || [];
            if (ops.length > 0) {
                // Shift existing char-tracking indices before applying the delta
                this.shiftTrackingIndices(ops);

                const {text, cursor, inserts, deletes} = this.applyDelta(this.text, ops);

                // Track new insertions for visual highlights
                for (const {pos, text: ins} of inserts) {
                    for (let i = 0; i < ins.length; i++) {
                        if (ev.meta === 'paste') {
                            this.pastedChars.push({index: pos + i});
                        } else if (ev.meta === 'autoinsert') {
                            this.aiChars.push({index: pos + i});
                        } else if (ev.meta !== 'undo' && ev.meta !== 'redo') {
                            this.highlightedChars.push({
                                index: pos + i,
                                chars: ins[i],
                                time: this.currentTime,
                                expiresAt: this.currentTime + 1500,
                            });
                        }
                    }
                }

                // Track deletions for strikethrough display
                for (const {pos, content} of deletes) {
                    this.deletedChars.push({
                        index: pos,
                        chars: content,
                        time: this.currentTime,
                        expiresAt: this.currentTime + 2000,
                    });
                }

                this.text = text;
                this.cursorPosition = cursor;
            }

            this.highlightedChars = this.highlightedChars.filter(h => !h.expiresAt || h.expiresAt > this.currentTime);
            this.deletedChars = this.deletedChars.filter(d => !d.expiresAt || d.expiresAt > this.currentTime);
            this.currentEventIndex++;
        }

        this.updateDisplayText(this.text, this.cursorPosition, this.highlightedChars, this.deletedChars);
        if (this.totalDuration > 0) {
            const percentComplete = Math.min((this.currentTime / this.totalDuration) * 100, 100);
            this.setScrubberVal(percentComplete);
        }

        if (this.replayInProgress) {
            // Detect first crossing of each break boundary and pause to show overlay.
            for (let bi = 0; bi < this.breakPositions.length; bi++) {
                const bp = this.breakPositions[bi];
                if (bp.at <= this.currentTime && !this.shownBreakIndices.has(bi)) {
                    this.shownBreakIndices.add(bi);
                    this.showAwayOverlay(bp.duration);
                    this.replayTimeout = setTimeout(() => {
                        this.hideAwayOverlay();
                        this.replayLog();
                    }, 4000);
                    return;
                }
            }

            const baseIncrement = 100;
            const brakeZone = 200;
            const nearBreak = this.breakPositions.some(
                bp => Math.abs(this.currentTime - bp.at) < brakeZone
            );
            const effectiveIncrement = nearBreak ? Math.round(baseIncrement * 0.2) : baseIncrement;
            const incrementTime = effectiveIncrement / this.speed;
            this.currentTime += effectiveIncrement;
            if (this.currentEventIndex >= this.totalEvents) {
                if (this.loop) {
                    this.startReplay(true);
                } else {
                    this.stopReplay(true);
                    this.updateDisplayText(this.text, this.cursorPosition, [], []);
                }
            } else {
                this.nextTickAt = Date.now() + incrementTime;
                this.replayTimeout = setTimeout(() => this.replayLog(), incrementTime);
            }
        }
    }

    // Pure text-only delta application — no cursor or highlight tracking.
    // Used for building seek checkpoints without side effects.
    applyDeltaText(text, ops) {
        let result = '';
        let pos = 0;
        for (const op of ops) {
            if (op.retain) {
                result += text.slice(pos, pos + op.retain);
                pos += op.retain;
            } else if (op.insert) {
                result += op.insert;
            } else if (op.delete) {
                pos += op.delete;
            }
        }
        return result + text.slice(pos);
    }

    // Pre-compute text snapshots every STEP events so skipToTime can start
    // from the nearest checkpoint instead of replaying from event 0.
    buildSeekCheckpoints() {
        const STEP = 200;
        this.seekCheckpoints = [{eventIndex: 0, normalizedTime: 0, text: ''}];
        let text = '';
        for (let i = 0; i < this.logData.length; i++) {
            const ops = this.logData[i].d || [];
            if (ops.length > 0) {
                text = this.applyDeltaText(text, ops);
            }
            if (i > 0 && i % STEP === 0) {
                this.seekCheckpoints.push({
                    eventIndex: i,
                    normalizedTime: this.logData[i].normalizedTime || 0,
                    text,
                });
            }
        }
    }

    showAwayOverlay(durationMs) {
        if (!this.outputElement) { return; }
        let overlay = this.outputElement.querySelector('.tiny_authory_tech_away_overlay');
        if (!overlay) {
            const ps = window.getComputedStyle(this.outputElement).position;
            if (ps === 'static') { this.outputElement.style.position = 'relative'; }
            overlay = document.createElement('div');
            overlay.className = 'tiny_authory_tech_away_overlay';
            overlay.style.cssText = 'position:absolute;inset:0;display:flex;align-items:center;' +
                'justify-content:center;background:rgba(220,220,220,0.75);z-index:20;' +
                'border-radius:4px;pointer-events:none;';
            const msg = document.createElement('div');
            msg.className = 'tiny_authory_tech_away_msg';
            msg.style.cssText = 'text-align:center;font-family:"Lexend",sans-serif;font-size:14px;' +
                'color:#3a3a3a;background:rgba(255,255,255,0.92);border-radius:10px;' +
                'padding:14px 22px;box-shadow:0 2px 12px rgba(0,0,0,0.13);line-height:1.6;';
            overlay.appendChild(msg);
            this.outputElement.appendChild(overlay);
        }
        const hours = Math.floor(durationMs / 3600000);
        const mins = Math.round((durationMs % 3600000) / 60000);
        const timeStr = hours > 0 ? `${hours}h ${mins}m` : `${mins}m`;
        const msgEl = overlay.querySelector('.tiny_authory_tech_away_msg');
        msgEl.innerHTML = '\u{1F4A4}&nbsp; Student away for <strong>' + timeStr + '</strong>';
        overlay.style.display = 'flex';
    }

    hideAwayOverlay() {
        if (!this.outputElement) { return; }
        const overlay = this.outputElement.querySelector('.tiny_authory_tech_away_overlay');
        if (overlay) { overlay.style.display = 'none'; }
    }

    destroy() {
        if (this.replayTimeout) { clearTimeout(this.replayTimeout); }
        if (this._docMouseMove) { document.removeEventListener('mousemove', this._docMouseMove); }
        if (this._docMouseUp) { document.removeEventListener('mouseup', this._docMouseUp); }
        this.hideAwayOverlay();
        this.replayInProgress = false;
    }

    getLineAndColumn(text, pos) {
        const before = text.substring(0, pos);
        const lineIndex = before.split('\n').length - 1;
        const col = before.length - before.lastIndexOf('\n') - 1;
        return {lineIndex, col};
    }

    // Handle keydown events (e.g., typing, backspace, Ctrl+V)
    processKeydownEvent(event, text, cursor, highlights, deletions) {
        const key = event.key;
        const charToInsert = this.applyKey(key);

        // Handle copy operation (Ctrl+C)
        if (this.isCopyOperation(key)) {
            return {text, cursor, updatedHighlights: highlights, updatedDeleted: deletions};
        }

        // Handle undo operation (Ctrl+Z)
        if (this.isUndoOperation(key)) {
            return this.handleUndoOperation(event, text, cursor, highlights, deletions);
        }

        // Detect selection for current event
        const currentEventIndex = this.currentEventIndex;
        const selection = this.detectSelection(currentEventIndex);

        // Handle paste operation (Ctrl+V)
        if (this.isPasteOperation(key, event)) {
            return this.handlePasteOperation(event, selection, text, cursor, highlights, deletions);
        }

        // Update modifier key states
        this.updateModifierStates(key);

        // Handle selection deletion with Backspace/Delete
        if (this.isSelectionDeletion(key, selection)) {
            ({text, cursor} = this.handleSelectionDeletion(selection, text, cursor, deletions));
            return {text, cursor, updatedHighlights: highlights, updatedDeleted: deletions};
        }

        // Process various key operations
        return this.processKeyOperation(key, charToInsert, text, cursor, highlights, deletions, selection);
    }

    isCopyOperation(key) {
        return (key === 'c' || key === 'C') && (this.isControlKeyPressed || this.isMetaKeyPressed);
    }

    isUndoOperation(key) {
        return (key === 'z' || key === 'Z') && (this.isControlKeyPressed || this.isMetaKeyPressed);
    }

    handleUndoOperation(event, text, cursor, highlights, deletions) {
        const nextEventIndex = this.currentEventIndex + 1;
        if (nextEventIndex < this.logData.length) {
            const nextEvent = this.logData[nextEventIndex];

            if (nextEvent.event === 'keyUp' && (nextEvent.key === 'z' || nextEvent.key === 'Z')) {
                const newPosition = nextEvent.rePosition;
                if (newPosition < cursor && text.length > 0) {
                    const textBeforeUndo = text;
                    text = text.substring(0, newPosition) + text.substring(cursor);
                    cursor = newPosition;

                    // Mark as deleted for visual effect
                    for (let i = 0; i < textBeforeUndo.length && i < cursor; i++) {
                        deletions.push({
                            index: newPosition,
                            chars: textBeforeUndo[i],
                            time: this.currentTime,
                            expiresAt: this.currentTime + 2000
                        });
                    }
                }
            }
        }

        this.isControlKeyPressed = false;
        this.isMetaKeyPressed = false;

        return {text, cursor, updatedHighlights: highlights, updatedDeleted: deletions};
    }

    isPasteOperation(key, event) {
        if ((key === 'v' || key === 'V') && (this.isControlKeyPressed || this.isMetaKeyPressed)) {
            return (event.pastedContent && event.pastedContent.trim() !== '') ||
                   (this.pastedEvents && this.currentPasteIndex < this.pastedEvents.length);
        }
        return false;
    }

    handlePasteOperation(event, selection, text, cursor, highlights, deletions) {
        const pastedContent = event.pastedContent || this.pastedEvents[this.currentPasteIndex];

        if (selection) {
            ({text, cursor} = this.handleSelectionDeletion(selection, text, cursor, deletions));
        }

        ({text, cursor} = this.handlePasteInsert(pastedContent, text, cursor));
        this.currentPasteIndex++;
        this.resetModifierStates();
        this.isPasteEvent = false;

        return {text, cursor, updatedHighlights: highlights, updatedDeleted: deletions};
    }

    resetModifierStates() {
        this.isControlKeyPressed = false;
        this.isShiftKeyPressed = false;
        this.isMetaKeyPressed = false;
    }

    isSelectionDeletion(key, selection) {
        return (key === 'Backspace' || key === 'Delete') && selection && selection.length > 1;
    }

    processKeyOperation(key, charToInsert, text, cursor, highlights, deletions, selection) {
        if (this.isCtrlBackspace(key, cursor) || this.isAltBackspace(key, cursor)) {
            ({text, cursor} = this.handleCtrlBackspace(text, cursor, deletions));
        } else if (this.isCtrlDelete(key, cursor, text)) {
            ({text} = this.handleCtrlDelete(text, cursor, deletions));
        } else if (this.isCtrlArrowMove(key) || this.isAltArrowMove(key)) {
            cursor = this.handleCtrlArrowMove(key, text, cursor);
        } else if (this.isRegularBackspace(key, cursor)) {
            ({text, cursor} = this.handleBackspace(text, cursor, deletions));
        } else if (this.isRegularDelete(key, cursor, text)) {
            ({text} = this.handleDelete(text, cursor, deletions));
        } else if (this.isArrowUp(key)) {
            cursor = this.handleArrowUp(text, cursor);
        } else if (this.isArrowDown(key)) {
            cursor = this.handleArrowDown(text, cursor);
        } else if (this.isRegularArrowMove(key)) {
            cursor = this.handleArrowMove(key, text, cursor);
        } else if (charToInsert && charToInsert.length > 0) {
            if (selection && selection.length > 0) {
                ({text, cursor} = this.handleSelectionDeletion(selection, text, cursor, deletions));
            }
            ({text, cursor} = this.handleCharacterInsert(charToInsert, text, cursor, highlights));
        }

        return {text, cursor, updatedHighlights: highlights, updatedDeleted: deletions};
    }

    detectSelection(eventIndex) {
        const currentEvent = this.logData[eventIndex];

        if (currentEvent.event?.toLowerCase() === 'keydown' &&
            (currentEvent.key === 'Backspace' || currentEvent.key === 'Delete')) {

            const currentPos = currentEvent.rePosition;
            return this.processDetection(currentPos, currentEvent, eventIndex);
        }
        return null;
    }

    processDetection(currentPos, currentEvent, eventIndex) {
        for (let i = eventIndex + 1; i < this.logData.length; i++) {
            const nextEvent = this.logData[i];

            if (nextEvent.event?.toLowerCase() === 'keyup' &&
                nextEvent.key === currentEvent.key) {

                const nextPos = nextEvent.rePosition;

                // Calculate the difference in positions
                const positionDiff = Math.abs(currentPos - nextPos);

                if (positionDiff > 1) {
                    return {
                        start: Math.min(currentPos, nextPos),
                        end: Math.max(currentPos, nextPos),
                        length: positionDiff
                    };
                } else if (positionDiff === 1) {
                    if (currentEvent.key === 'Backspace') {
                        return {
                            start: nextPos,
                            end: currentPos,
                            length: 1
                        };
                    } else {
                        return {
                            start: currentPos,
                            end: nextPos,
                            length: 1
                        };
                    }
                }
                break;
            }
        }
        return null;
    }

    handleSelectionDeletion(selection, text, cursor, deletions) {
        const {start, end, length} = selection;

        // Add each character in the selection to the deletions array
        for (let i = start; i < end && i < text.length; i++) {
            deletions.push({
                index: start,
                chars: text[i],
                time: this.currentTime,
                expiresAt: this.currentTime + 2000
            });
        }

        text = text.substring(0, start) + text.substring(end);

        this.shiftPastedCharsIndices(start, length);

        cursor = start;

        return {text, cursor};
    }

    // Handle Paste events to highlight pasted text
    handlePasteInsert(pastedContent, text, cursor) {
        const insertText = pastedContent || '';
        text = text.substring(0, cursor) + insertText + text.substring(cursor);

        // Mark characters as pasted for bold styling
        if (insertText.trim() !== '') {
            for (let i = 0; i < insertText.length; i++) {
                if (!this.pastedChars) {
                    this.pastedChars = [];
                }
                this.pastedChars.push({
                    index: cursor + i,
                    chars: insertText[i]
                });
            }
        }

        return {text, cursor: cursor + insertText.length};
    }

    // Adjusts pasted chars indices after deletion to maintain styling for pasted text
    shiftPastedCharsIndices(startIndex, numDeleted) {
        this.pastedChars = this.pastedChars.map(p => {
            if (p.index >= startIndex + numDeleted) {
                return {...p, index: p.index - numDeleted};
            } else if (p.index >= startIndex && p.index < startIndex + numDeleted) {
                // Remove pasted characters that were deleted
                return null;
            }
            return p;
        }).filter(p => p !== null);

        if (this.aiChars) {
            this.aiChars = this.aiChars.map(p => {
                if (p.index >= startIndex + numDeleted) {
                    return {...p, index: p.index - numDeleted};
                } else if (p.index >= startIndex && p.index < startIndex + numDeleted) {
                    return null;
                }
                return p;
            }).filter(p => p !== null);
        }
    }

    // Update state for modifier keys (Control, paste events)
    updateModifierStates(key) {
        if (key === 'Control') {
            this.isControlKeyPressed = true;
        } else if (key === 'Alt') {
            this.isAltKeyPressed = true;
        } else if (key === 'Shift') {
            this.isShiftKeyPressed = true;
        } else if (key === 'Meta') {
            this.isMetaKeyPressed = true;
        } else if ((key === 'v' || key === 'V') && (this.isControlKeyPressed || this.isMetaKeyPressed)) {
            this.isPasteEvent = true;
        } else if (!['Control', 'Alt', 'Meta', 'Backspace', 'Delete',
                      'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(key)) {
            this.isControlKeyPressed = false;
            this.isAltKeyPressed = false;
            this.isShiftKeyPressed = false;
            this.isMetaKeyPressed = false;
            this.isPasteEvent = false;
        }
    }

    isCtrlBackspace(key, cursor) {
        return key === 'Backspace' && this.isControlKeyPressed && cursor > 0;
    }

    isCtrlDelete(key, cursor, text) {
        return key === 'Delete' && this.isControlKeyPressed && cursor < text.length;
    }

    isCtrlArrowMove(key) {
        return this.isControlKeyPressed && (key === 'ArrowLeft' || key === 'ArrowRight');
    }

    isAltArrowMove(key) {
        return this.isAltKeyPressed && (key === 'ArrowLeft' || key === 'ArrowRight');
    }

    isAltBackspace(key, cursor) {
        return key === 'Backspace' && this.isAltKeyPressed && cursor > 0;
    }

    isRegularBackspace(key, cursor) {
        return key === 'Backspace' && !this.isPasteEvent && cursor > 0;
    }

    isRegularDelete(key, cursor, text) {
        return key === 'Delete' && !this.isControlKeyPressed && cursor < text.length;
    }

    isRegularArrowMove(key) {
        return !this.isControlKeyPressed && (key === 'ArrowLeft' || key === 'ArrowRight');
    }

    isArrowUp(key) {
        return key === 'ArrowUp';
    }

    isArrowDown(key) {
        return key === 'ArrowDown';
    }

    handleCtrlArrowMove(key, text, cursor) {
        return key === 'ArrowLeft'
            ? this.findPreviousWordBoundary(text, cursor)
            : this.findNextWordBoundary(text, cursor);
    }

    handleBackspace(text, cursor, deletions) {
        deletions.push({
            index: cursor - 1,
            chars: text[cursor - 1],
            time: this.currentTime,
            expiresAt: this.currentTime + 2000
        });
        this.shiftPastedCharsIndices(cursor - 1, 1);
        return {
            text: text.substring(0, cursor - 1) + text.substring(cursor),
            cursor: cursor - 1
        };
    }

    handleDelete(text, cursor, deletions) {
        deletions.push({
            index: cursor,
            chars: text[cursor],
            time: this.currentTime,
            expiresAt: this.currentTime + 2000
        });
        this.shiftPastedCharsIndices(cursor, 1);
        return {
            text: text.substring(0, cursor) + text.substring(cursor + 1),
            cursor
        };
    }

    handleArrowMove(key, text, cursor) {
        return key === 'ArrowLeft'
            ? Math.max(0, cursor - 1)
            : Math.min(text.length, cursor + 1);
    }

    handleCharacterInsert(charToInsert, text, cursor, highlights) {
        text = text.substring(0, cursor) + charToInsert + text.substring(cursor);
        // Shift pasted chars indices after the insertion point
        if (this.pastedChars) {
            this.pastedChars = this.pastedChars.map(p => {
                return p.index >= cursor ? {...p, index: p.index + 1} : p;
            });
        }
        if (this.aiChars) {
            this.aiChars = this.aiChars.map(p => {
                return p.index >= cursor ? {...p, index: p.index + 1} : p;
            });
        }
        if (charToInsert.trim() !== '') {
            highlights.push({
                index: cursor,
                chars: charToInsert,
                time: this.currentTime,
                expiresAt: this.currentTime + 1500
            });
        }
        return {text, cursor: cursor + 1};
    }

    handleCtrlDelete(text, cursor, deletions) {
        const wordEnd = this.findNextWordBoundary(text, cursor);
        const wordToDelete = text.substring(cursor, wordEnd);
        for (let i = 0; i < wordToDelete.length; i++) {
            deletions.push({
                index: cursor + i,
                chars: wordToDelete[i],
                time: this.currentTime,
                expiresAt: this.currentTime + 2000
            });
        }
        this.shiftPastedCharsIndices(cursor, wordToDelete.length);
        return {
            text: text.substring(0, cursor) + text.substring(wordEnd),
            cursor
        };
    }

    handleArrowUp(text, cursor) {
        const lines = text.split('\n');
        const {lineIndex, col} = this.getLineAndColumn(text, cursor);
        if (lineIndex > 0) {
            const prevLine = lines[lineIndex - 1];
            cursor = lines.slice(0, lineIndex - 1).join('\n').length + 1 + Math.min(col, prevLine.length);
        } else {
            cursor = 0;
        }
        return cursor;
    }

    handleArrowDown(text, cursor) {
        const lines = text.split('\n');
        const {lineIndex, col} = this.getLineAndColumn(text, cursor);
        if (lineIndex < lines.length - 1) {
            const nextLine = lines[lineIndex + 1];
            cursor = lines.slice(0, lineIndex + 1).join('\n').length + 1 + Math.min(col, nextLine.length);
        } else {
            cursor = text.length;
        }
        return cursor;
    }

    handleCtrlBackspace(text, cursor, deletions) {
        let wordStart = cursor;
        while (wordStart > 0 && text[wordStart - 1] === ' ') {
            wordStart--;
        }
        while (wordStart > 0 && text[wordStart - 1] !== ' ') {
            wordStart--;
        }
        const wordToDelete = text.substring(wordStart, cursor);
        for (let i = 0; i < wordToDelete.length; i++) {
            deletions.push({
                index: wordStart + i,
                chars: wordToDelete[i],
                time: this.currentTime,
                expiresAt: this.currentTime + 2000
            });
        }
        this.shiftPastedCharsIndices(wordStart, wordToDelete.length);
        return {text: text.substring(0, wordStart) + text.substring(cursor), cursor: wordStart};
    }

    // Finds the index of the next word boundary after the cursor position
    findNextWordBoundary(text, cursor) {
        if (!text || cursor >= text.length) {
            return cursor;
        }
        if (text[cursor] === ' ') {
            while (cursor < text.length && text[cursor] === ' ') {
                 cursor++;
            }
        }
        if (cursor >= text.length) {
            let lastNonSpace = text.length - 1;
            while (lastNonSpace >= 0 && text[lastNonSpace] === ' ') {
                 lastNonSpace--;
            }
            return lastNonSpace + 1;
        }
        let wordEnd = cursor;
        while (wordEnd < text.length && text[wordEnd] !== ' ') {
             wordEnd++;
         }
        return wordEnd;
    }

    // Finds the index of the previous word boundary before the cursor position
    findPreviousWordBoundary(text, cursor) {
        if (cursor <= 0) {
            return 0;
        }
        let pos = cursor - 1;
        while (pos > 0 && (text[pos] === ' ' || text[pos] === '\n')) {
             pos--;
        }
        while (pos > 0 && text[pos - 1] !== ' ' && text[pos - 1] !== '\n') {
             pos--;
        }

        return pos;
    }

    skipToEnd() {
        if (this.replayInProgress) {
            this.replayInProgress = false;
        }
        let text = '';
        let cursor = 0;
        for (const ev of this.logData) {
            const ops = ev.d || [];
            if (ops.length > 0) {
                const result = this.applyDelta(text, ops);
                text = result.text;
                cursor = result.cursor;
            }
        }
        this.text = text;
        this.cursorPosition = cursor;
        this.outputElement.innerHTML = this.escapeHtml(text).replace(/\n/g, '<br>');
        this.setScrubberVal(100);
    }

    // Used by the scrubber to skip to a certain percentage of data
    skipToTime(percentage) {
        const wasPlaying = this.replayInProgress;
        this.stopReplay();

        const targetTime = (this.totalDuration * percentage) / 100;
        this.currentTime = targetTime;
        this.currentEventIndex = 0;
        this.text = '';
        this.cursorPosition = 0;
        this.highlightedChars = [];
        this.deletedChars = [];
        this.pastedChars = [];
        this.aiChars = [];
        this.isPasteEvent = false;

        // Find the nearest checkpoint before targetTime and start from there
        // instead of replaying from event 0 every time.
        let cpIdx = 0;
        let cpText = '';
        for (const cp of this.seekCheckpoints) {
            if (cp.normalizedTime <= targetTime) {
                cpIdx = cp.eventIndex;
                cpText = cp.text;
            } else {
                break;
            }
        }
        this.text = cpText;
        this.currentEventIndex = cpIdx;

        // Apply all deltas from checkpoint up to targetTime to reconstruct state
        for (let i = cpIdx; i < this.logData.length; i++) {
            const ev = this.logData[i];
            if (ev.normalizedTime && ev.normalizedTime > targetTime) {
                this.currentEventIndex = i;
                break;
            }
            const ops = ev.d || [];
            if (ops.length > 0) {
                this.shiftTrackingIndices(ops);
                const {text, cursor, inserts, deletes} = this.applyDelta(this.text, ops);

                for (const {pos, text: ins} of inserts) {
                    for (let j = 0; j < ins.length; j++) {
                        if (ev.meta === 'paste') {
                            this.pastedChars.push({index: pos + j});
                        } else if (ev.meta === 'autoinsert') {
                            this.aiChars.push({index: pos + j});
                        } else if (ev.meta !== 'undo' && ev.meta !== 'redo') {
                            this.highlightedChars.push({
                                index: pos + j,
                                chars: ins[j],
                                time: ev.normalizedTime,
                                expiresAt: ev.normalizedTime + 1500,
                            });
                        }
                    }
                }
                for (const {pos, content} of deletes) {
                    this.deletedChars.push({
                        index: pos,
                        chars: content,
                        time: ev.normalizedTime,
                        expiresAt: ev.normalizedTime + 2000,
                    });
                }

                this.text = text;
                this.cursorPosition = cursor;
            }
            this.currentEventIndex = i + 1;
        }

        this.highlightedChars = this.highlightedChars.filter(h => !h.expiresAt || h.expiresAt > targetTime);
        this.deletedChars = this.deletedChars.filter(d => !d.expiresAt || d.expiresAt > targetTime);
        this.updateDisplayText(this.text, this.cursorPosition, this.highlightedChars, this.deletedChars);
        this.setScrubberVal(percentage);

        if (wasPlaying) {
            this.replayInProgress = true;
            this.replayLog();
        }
    }

    // Update display with text, cursor, highlights and deletions.
    // eslint-disable-next-line complexity
    updateDisplayText(text, cursorPosition, highlights, deletions) {
        let html = '';
        const highlightMap = {};
        const deletionMap = {};
        const pastedMap = {};
        const aiMap = {};
        const currentTime = this.currentTime;

        highlights.forEach(h => {
            let opacity = 1;
            if (h.expiresAt && h.expiresAt - currentTime < 500) {
                opacity = Math.max(0, (h.expiresAt - currentTime) / 500);
            }
            highlightMap[h.index] = {chars: h.chars, opacity};
        });

        deletions.forEach(d => {
            let opacity = 0.5;
            if (d.expiresAt && d.expiresAt - currentTime < 500) {
                opacity = Math.max(0, ((d.expiresAt - currentTime) / 500) * 0.5);
            }
            deletionMap[d.index] = {chars: d.chars, opacity};
        });

        // Process pasted characters for bold styling
        if (this.pastedChars) {
            this.pastedChars.forEach(p => {
                if (p.index < text.length) {
                    pastedMap[p.index] = true;
                }
            });
        }

        // Process AI characters for styling
        if (this.aiChars) {
            this.aiChars.forEach(p => {
                if (p.index < text.length) {
                    aiMap[p.index] = true;
                }
            });
        }

        // Find if we have out-of-bounds deletions (from Control+Backspace)
        const outOfRangeDeletions = deletions.filter(d => d.index >= text.length);
        const textLines = text.split('\n');
        let currentPosition = 0;

        for (let lineIndex = 0; lineIndex < textLines.length; lineIndex++) {
            const line = textLines[lineIndex];
            for (let i = 0; i < line.length; i++) {
                if (currentPosition === cursorPosition) {
                    html += '<span class="tiny_authory_tech-cursor"></span>';
                }
                const char = line[i];
                if (deletionMap[currentPosition]) {
                    html += `<span class="tiny_authory_tech-deleted-char" style="opacity:
                        ${deletionMap[currentPosition].opacity};">${deletionMap[currentPosition].chars}</span>`;
                }
                const isPasted = pastedMap[currentPosition];
                const isAi = aiMap[currentPosition];
                const isHighlighted = highlightMap[currentPosition] && char !== ' ';

                if (isPasted && isHighlighted) {
                    html += `<span class="tiny_authory_tech-pasted-char tiny_authory_tech-highlighted-char" style="opacity:
                        ${highlightMap[currentPosition].opacity};">${char}</span>`;
                } else if (isAi && isHighlighted) {
                    html += `<span class="tiny_authory_tech-ai-char tiny_authory_tech-highlighted-char" style="opacity:
                        ${highlightMap[currentPosition].opacity};">${char}</span>`;
                } else if (isPasted) {
                    html += `<span class="tiny_authory_tech-pasted-char">${char === ' ' ? ' ' : this.escapeHtml(char)}</span>`;
                } else if (isAi) {
                    html += `<span class="tiny_authory_tech-ai-char">${char === ' ' ? ' ' : this.escapeHtml(char)}</span>`;
                } else if (isHighlighted) {
                    html += `<span class="tiny_authory_tech-highlighted-char" style="opacity:
                        ${highlightMap[currentPosition].opacity};">${char}</span>`;
                } else {
                    html += char === ' ' ? ' ' : this.escapeHtml(char);
                }
                currentPosition++;
            }
            if (currentPosition === cursorPosition) {
                html += '<span class="tiny_authory_tech-cursor"></span>';
            }
            if (lineIndex < textLines.length - 1) {
                html += '<br>';
                currentPosition++;
            }
        }

        if (cursorPosition === text.length && !html.endsWith('<span class="tiny_authory_tech-cursor"></span>')) {
            html += '<span class="tiny_authory_tech-cursor"></span>';
        }

        if (outOfRangeDeletions.length > 0) {
            outOfRangeDeletions.sort((a, b) => a.index - b.index);
            const cursorHTML = '<span class="tiny_authory_tech-cursor"></span>';
            const cursorPos = html.lastIndexOf(cursorHTML);
            if (cursorPos !== -1) {
                let deletedWordHTML = '<span class="tiny_authory_tech-deleted-char" style="opacity: 0.5;">';
                outOfRangeDeletions.forEach(d => {
                    deletedWordHTML += d.chars;
                });
                deletedWordHTML += '</span>';
                html = html.substring(0, cursorPos) + deletedWordHTML + html.substring(cursorPos);
            }
        }

        const wasScrolledToBottom = this.outputElement.scrollHeight -
            this.outputElement.clientHeight <= this.outputElement.scrollTop + 1;
        this.outputElement.innerHTML = html;

        if (wasScrolledToBottom || this.isCursorBelowViewport()) {
            this.outputElement.scrollTop = this.outputElement.scrollHeight;
        }
    }

    // Check if cursor is below visible viewport
    isCursorBelowViewport() {
        const cursorElement = this.outputElement.querySelector('.tiny_authory_tech-cursor:last-of-type');
        if (!cursorElement) {
            return false;
        }

        const cursorRect = cursorElement.getBoundingClientRect();
        const outputRect = this.outputElement.getBoundingClientRect();

        return cursorRect.bottom > outputRect.bottom;
    }

    escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Used in various places to add a keydown, backspace, etc. to the output
    applyKey(key) {
        switch (key) {
            case 'Enter':
                return '\n';
            case 'Backspace':
            case 'Delete':
            case 'ControlBackspace':
                return '';
            case ' ':
                 return ' ';
            default:
                return !['Shift', 'Ctrl', 'Alt', 'ArrowDown', 'ArrowUp', 'Control', 'ArrowRight',
                    'ArrowLeft', 'Meta', 'CapsLock', 'Tab', 'Escape', 'Delete', 'PageUp', 'PageDown',
                    'Insert', 'Home', 'End', 'NumLock', 'AudioVolumeUp', 'AudioVolumeDown',
                    'MediaPlayPause', 'Dead', 'Process', 'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7',
                    'F8', 'F9', 'F10', 'F11', 'F12', 'PrintScreen', 'UnIdentified'].includes(key) ? key : '';
        }
    }
}
