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
 * Teacher writing analytics dashboard for assignment view.
 *
 * @module     tiny_authory_tech/assign_dashboard
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/chartjs', 'core/ajax', 'tiny_authory_tech/replay'], function($, Chart, Ajax, Replay) {

    /**
     * Highlight a word element in the essay: scroll to it and flash the animation.
     * Exposed globally so inline onclick handlers in the template can call it.
     *
     * @param {string} elementId
     */
    window.authoryHighlight = function(elementId) {
        var el = document.getElementById(elementId);
        if (!el) {
            return;
        }
        el.scrollIntoView({behavior: 'smooth', block: 'center'});
        el.classList.add('flash');
        setTimeout(function() {
            el.classList.remove('flash');
        }, 1500);
    };

    /**
     * Build student tab button and empty content panel.
     *
     * @param {Object} student  {userid, fullname, file_id}
     * @param {Element} tabsEl  .authory-tabs container
     * @param {Element} wrapEl  .authory-tabs-wrap container
     */
    function buildStudentTab(student, tabsEl, wrapEl) {
        var tabId = 'authory-student-' + student.userid;

        var btn = document.createElement('button');
        btn.className = 'authory-tab';
        btn.dataset.tab = tabId;
        btn.textContent = student.fullname;
        tabsEl.appendChild(btn);

        var panel = document.createElement('div');
        panel.id = tabId;
        panel.className = 'authory-tab-content';
        panel.dataset.userid = student.userid;
        panel.dataset.fileId = student.file_id || '';
        panel.dataset.fullname = student.fullname;
        panel.dataset.loaded = '0';
        panel.innerHTML = '<div class="authory-student-card">'
            + '<h2 class="authory-student-name">' + escapeHtml(student.fullname) + '</h2>'
            + '<p class="authory-student-loading">Loading data\u2026</p>'
            + '</div>';
        wrapEl.appendChild(panel);
    }

    /**
     * Activate a tab by its panel ID, deactivating all others.
     * @param {string} tabId
     */
    function activateTab(tabId) {
        $('.authory-tab').removeClass('active');
        $('.authory-tab-content').removeClass('active');
        $('[data-tab="' + tabId + '"]').addClass('active');
        $('#' + tabId).addClass('active');
    }

    /**
     * Lazy-load student stats and render the panel (only on first activation).
     * @param {Element} panel
     * @param {boolean} enableReplay
     */
    function loadStudentPanel(panel, enableReplay) {
        var userid = parseInt(panel.dataset.userid, 10);
        var fileId = parseInt(panel.dataset.fileId, 10);
        if (!userid || !fileId) {
            return;
        }

        var call = Ajax.call([{
            methodname: 'authory_tech_get_student_stats',
            args: {resource_id: fileId, userid: userid},
        }]);

        call[0].done(function(json) {
            try {
                var stats = JSON.parse(json);
                renderStudentPanel(panel, stats, enableReplay);
            } catch(e) {
                panel.innerHTML = '<p style="color:#c00;padding:20px">Error loading student data.</p>';
            }
        }).fail(function() {
            panel.innerHTML = '<p style="color:#c00;padding:20px">Failed to load student data.</p>';
        });
    }

    /**
     * Render the full student panel with metrics and essay.
     * @param {Element} panel
     * @param {Object}  stats        Response from authory_tech_get_student_stats
     * @param {boolean} enableReplay Whether the replay feature is enabled
     */
    function renderStudentPanel(panel, stats, enableReplay) {
        var fullname      = panel.dataset.fullname || '';
        var userid        = panel.dataset.userid || '';
        var wpm           = Math.round(stats.avg_wpm || 0);
        var classAvgWpm   = Math.round(stats.class_avg_wpm || 0);
        var wpmDiff       = parseFloat(stats.wpm_diff) || 0;
        var sessionStr  = formatDuration(stats.duration_seconds || 0);
        var typingStr   = formatDuration(stats.typing_duration_seconds || 0);
        var unusualWords  = stats.unusual_words || [];
        var pastedTexts   = stats.pasted_texts  || [];
        var submText      = stats.submission_text || '';
        var filename      = stats.filename || '';

        var absDiff  = Math.abs(wpmDiff);
        var devClass = absDiff < 5 ? 'deviation-low' : (absDiff < 15 ? 'deviation-medium' : 'deviation-high');
        var sign     = wpmDiff >= 0 ? '+' : '';
        var diffText = sign + wpmDiff.toFixed(1) + ' WPM vs class average (' + classAvgWpm + ' WPM)';

        // Progress bar: student WPM as proportion of 2× class avg (capped at 100%).
        var progressPct = classAvgWpm > 0
            ? Math.min(100, Math.round(wpm / (classAvgWpm * 2) * 100))
            : Math.min(100, wpm);

        var html = '<div class="authory-student-card">'
            + '<h2 class="authory-student-name">' + escapeHtml(fullname) + '</h2>'
            + '</div>';

        // Metrics grid
        html += '<div class="authory-metrics-grid">';

        // Typing speed
        html += '<div class="authory-metric-card">'
            + '<div class="authory-metric-icon">\u2328</div>'
            + '<div class="authory-metric-body">'
            + '<p class="authory-metric-title">Typing Speed</p>'
            + '<span class="authory-big-number">' + wpm + '</span>'
            + '<span class="authory-unit">words per minute</span>'
            + '<div class="authory-progress-bar">'
            + '<div class="authory-progress-fill" style="width:' + progressPct + '%"></div>'
            + '</div>'
            + '<p class="authory-comparison ' + devClass + '">' + diffText + '</p>'
            + '</div></div>';

        // Unusual words
        html += '<div class="authory-metric-card">'
            + '<div class="authory-metric-icon">&#128269;</div>'
            + '<div class="authory-metric-body">'
            + '<p class="authory-metric-title">Unusual Words</p>';
        if (unusualWords.length > 0) {
            html += '<p class="authory-metric-hint">(click to highlight in text)</p>'
                + '<ul class="authory-word-list">';
            unusualWords.forEach(function(word, i) {
                html += '<li class="authory-word-tag" onclick="authoryHighlight(\'unusual-' + i + '\')">'
                    + escapeHtml(word) + '</li>';
            });
            html += '</ul>';
        } else {
            html += '<p class="authory-no-paste">No unusual words detected</p>';
        }
        html += '</div></div>';

        // Pasted texts
        html += '<div class="authory-metric-card">'
            + '<div class="authory-metric-icon">&#128203;</div>'
            + '<div class="authory-metric-body">'
            + '<p class="authory-metric-title">Pasted Text Detection</p>';
        if (pastedTexts.length > 0) {
            pastedTexts.forEach(function(text, i) {
                var preview = text.length > 80 ? text.substring(0, 80) + '\u2026' : text;
                html += '<div class="authory-paste-alert">'
                    + '<p><strong>Paste ' + (i + 1) + '</strong>'
                    + ' <a href="javascript:void(0)" class="authory-goto-link"'
                    + ' onclick="authoryHighlight(\'pasted-' + i + '\')">(go to text)</a></p>'
                    + '<p>&ldquo;' + escapeHtml(preview) + '&rdquo;</p>'
                    + '</div>';
            });
        } else {
            html += '<p class="authory-no-paste">&#10003; No pasted text detected</p>';
        }
        html += '</div></div>';

        html += '</div>'; // end metrics-grid

        // Essay section
        if (submText) {
            var wordCount = submText.split(/\s+/).filter(Boolean).length;
            var essayHtml = buildEssayHtml(submText, unusualWords, pastedTexts);

            html += '<div class="authory-essay-section">'
                + '<h3 class="authory-essay-title">&#128221; Student Submission</h3>'
                + '<div class="authory-essay-meta">'
                + '<span><strong>Word count:</strong> ' + wordCount + ' words</span>'
                + '<span><strong>Typing time:</strong> ' + typingStr + '</span>'
                + '<span><strong>Session time:</strong> ' + sessionStr + '</span>'
                + '</div>'
                + '<div class="authory-essay-content">' + essayHtml + '</div>'
                + '</div>';
        }

        // Replay section — containers must exist in DOM before Replay is instantiated.
        if (enableReplay && filename) {
            html += '<div class="authory-replay-section">'
                + '<h3 class="authory-replay-title">&#9654; Writing Replay</h3>'
                + '<div id="player_' + userid + '" class="tiny_authory_tech_replay_control authory-replay-player">'
                + '</div>'
                + '<div id="content' + userid + '" class="authory-replay-output"></div>'
                + '</div>';
        }

        panel.innerHTML = html;

        if (enableReplay && filename) {
            new Replay('content' + userid, filename, 10, false, 'player_' + userid);
        }
    }

    /**
     * Format a duration in seconds as a human-readable string.
     * Shows seconds when under a minute, otherwise whole minutes.
     * @param {number} seconds
     * @returns {string}
     */
    function formatDuration(seconds) {
        if (!seconds || seconds <= 0) {
            return '—';
        }
        if (seconds < 60) {
            return Math.round(seconds) + ' sec';
        }
        return Math.round(seconds / 60) + ' min';
    }

    /**
     * Escape special HTML characters.
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Build annotated essay HTML: highlights unusual words and pasted-text spans.
     *
     * @param {string}   rawText      Plain submission text
     * @param {string[]} unusualWords Words to highlight (blue)
     * @param {string[]} pastedTexts  Pasted segments to highlight (red)
     * @returns {string} Safe HTML string
     */
    function buildEssayHtml(rawText, unusualWords, pastedTexts) {
        var marked = rawText;

        // Step 1 — mark pasted-text positions before escaping
        var pasteMarkers = [];
        pastedTexts.forEach(function(pt, i) {
            pt = pt.trim();
            if (!pt) {
                return;
            }
            var idx = marked.indexOf(pt);
            if (idx >= 0) {
                var marker = '\x01PASTE' + i + '\x01';
                pasteMarkers.push({marker: marker, text: pt, id: 'pasted-' + i});
                marked = marked.substring(0, idx) + marker + marked.substring(idx + pt.length);
            }
        });

        // Step 2 — HTML-escape (markers survive because \x01 is not &, <, >)
        marked = marked
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // Step 3 — mark unusual word positions with placeholders (\x02).
        // We cannot inject span HTML directly here because subsequent iterations would
        // match words inside previously-injected attribute text (e.g. title="…word…"),
        // producing malformed HTML. Placeholders are replaced with spans in step 3b,
        // after all unusual-word passes are done.
        var unusualMarkers = [];
        unusualWords.forEach(function(word, i) {
            if (!word) {
                return;
            }
            var esc = word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            // \x01 guards paste markers; \x02 guards already-placed unusual markers.
            var re = new RegExp(
                '(^|[^a-zA-Z\u00C0-\u017E\x01\x02])(' + esc + ')([^a-zA-Z\u00C0-\u017E\x01\x02]|$)',
                'i'
            );
            marked = marked.replace(re, function(m, pre, w, post) {
                var marker = '\x02UNUSUAL' + i + '\x02';
                unusualMarkers.push({marker: marker, word: word, matched: w, id: 'unusual-' + i});
                return pre + marker + post;
            });
        });

        // Step 4 — replace paste markers with highlighted spans
        pasteMarkers.forEach(function(pm) {
            var escapedText = pm.text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            marked = marked.split(pm.marker).join(
                '<span id="' + pm.id + '" class="highlight-pasted">' + escapedText + '</span>'
            );
        });

        // Step 4b — replace unusual word markers with highlighted spans
        unusualMarkers.forEach(function(um) {
            marked = marked.split(um.marker).join(
                '<span id="' + um.id + '" class="highlight-unusual" title="Unusual word: '
                + escapeHtml(um.word) + '">' + um.matched + '</span>'
            );
        });

        // Step 5 — paragraph wrap
        var paragraphs = marked.split(/\n\n+/).map(function(p) {
            return '<p>' + p.replace(/\n/g, '<br>') + '</p>';
        }).filter(function(p) {
            return p !== '<p></p>';
        });

        return paragraphs.join('');
    }

    return {
        init: function(cmid, enableReplay) {
            var urlParams = new URLSearchParams(window.location.search);
            var studentId = urlParams.get('student');

            // Tab switching with lazy load
            $(document).on('click', '.authory-tab', function() {
                var tabId = $(this).data('tab');
                activateTab(tabId);
                var panel = document.getElementById(tabId);
                if (panel && panel.dataset.loaded === '0') {
                    panel.dataset.loaded = '1';
                    loadStudentPanel(panel, enableReplay);
                }
            });

            var tabsEl = document.querySelector('.authory-tabs');
            var wrapEl = document.querySelector('.authory-tabs-wrap');
            if (!tabsEl || !wrapEl || !cmid) {
                return;
            }

            var calls = Ajax.call([
                {methodname: 'authory_tech_get_assign_dashboard_students', args: {cmid: cmid}},
                {methodname: 'authory_tech_get_assign_overview_stats',     args: {cmid: cmid}},
            ]);

            $.when(calls[0], calls[1]).done(function(studentsJson, overviewJson) {
                var students = JSON.parse(studentsJson);
                var overview = JSON.parse(overviewJson);

                // Build name lookup: userid → fullname
                var nameMap = {};
                students.forEach(function(s) {
                    nameMap[s.userid] = s.fullname;
                });

                // Build student tabs
                students.forEach(function(student) {
                    buildStudentTab(student, tabsEl, wrapEl);
                });

                // If a student param was passed, open and load their tab
                if (studentId) {
                    activateTab('authory-student-' + studentId);
                    var targetPanel = document.getElementById('authory-student-' + studentId);
                    if (targetPanel && targetPanel.dataset.loaded === '0') {
                        targetPanel.dataset.loaded = '1';
                        loadStudentPanel(targetPanel, enableReplay);
                    }
                }

                // Stat cards from students list
                var totalEl = document.getElementById('authory-overview-total');
                if (totalEl) {
                    totalEl.textContent = students.length;
                }
                var submEl = document.getElementById('authory-overview-submissions');
                if (submEl) {
                    submEl.textContent = students.length;
                }

                if (overview.error) {
                    window.console.warn('Overview stats error:', overview.error);
                    return;
                }

                // Stat cards from type-server
                var avgWpmEl = document.getElementById('authory-overview-avg-wpm');
                if (avgWpmEl) {
                    avgWpmEl.textContent = Math.round(overview.class_avg_wpm);
                }
                var avgTimeEl = document.getElementById('authory-overview-avg-time');
                if (avgTimeEl) {
                    avgTimeEl.textContent = formatDuration(overview.class_avg_duration_seconds || 0);
                }

                // Charts — resolve names from Moodle students list
                var statsStudents = overview.students || [];
                var names  = statsStudents.map(function(s) {
                    return nameMap[s.person_id] || ('Student ' + s.person_id);
                });
                var speeds = statsStudents.map(function(s) { return Math.round(s.avg_wpm); });
                var times  = statsStudents.map(function(s) { return Math.round(s.duration_seconds || 0); });

                buildCharts(names, speeds, Math.round(overview.class_avg_wpm), times);

            }).fail(function(err) {
                window.console.error('Failed to load dashboard data:', err);
            });

        }
    };

    /**
     * Build typing-speed and time-spent charts.
     *
     * @param {string[]} names   Student display names
     * @param {number[]} speeds  WPM per student
     * @param {number}   avg     Class average WPM
     * @param {number[]} times   Time spent in seconds per student
     */
    function buildCharts(names, speeds, avg, times) {
        var speedColors = speeds.map(function() { return 'rgba(35,79,194,0.8)'; });
        var speedBorders = speeds.map(function() { return '#234fc2'; });
        var timeColors = times.map(function() { return 'rgba(35,79,194,0.8)'; });
        var timeBorders = times.map(function() { return '#234fc2'; });
        var avgLine = speeds.map(function() { return avg; });
        var avgTimeLine = times.map(function() {
            return times.reduce(function(a, b) { return a + b; }, 0) / times.length;
        });

        var typingCanvas = document.getElementById('authory-typing-chart');
        if (typingCanvas) {
            new Chart(typingCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: names,
                    datasets: [
                        {
                            label: 'Typing Speed (WPM)',
                            data: speeds,
                            backgroundColor: speedColors,
                            borderColor: speedBorders,
                            borderWidth: 2,
                        },
                        {
                            label: 'Class Average',
                            data: avgLine,
                            type: 'line',
                            borderColor: '#e07b00',
                            borderWidth: 3,
                            borderDash: [5, 5],
                            fill: false,
                            pointRadius: 0,
                            pointStyle: 'line',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {legend: {display: true, position: 'top', labels: {usePointStyle: true}}},
                    scales: {y: {beginAtZero: true, title: {display: true, text: 'Words Per Minute'}}}
                }
            });
        }

        var timeCanvas = document.getElementById('authory-time-chart');
        if (timeCanvas) {
            new Chart(timeCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: names,
                    datasets: [
                        {
                            label: 'Time Spent (seconds)',
                            data: times,
                            backgroundColor: timeColors,
                            borderColor: timeBorders,
                            borderWidth: 2,
                        },
                        {
                            label: 'Class Average',
                            data: avgTimeLine,
                            type: 'line',
                            borderColor: '#e07b00',
                            borderWidth: 3,
                            borderDash: [5, 5],
                            fill: false,
                            pointRadius: 0,
                            pointStyle: 'line',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {legend: {display: true, position: 'top', labels: {usePointStyle: true}}},
                    scales: {y: {beginAtZero: true, title: {display: true, text: 'Seconds'}}}
                }
            });
        }
    }

});
