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

define(
    ['jquery', 'core/chartjs', 'core/ajax', 'core/templates', 'tiny_authory_tech/replay',
        'tiny_authory_tech/dashboard_metrics', 'core/str'],
    function($, Chart, Ajax, Templates, Replay, DashboardMetrics, Str) {

    // Requested once in init() and resolved before any tab can be clicked (tabs
    // don't exist in the DOM until the same $.when() block that fetches these
    // resolves). Labels inside a student's panel come from its Mustache template.
    var STRINGS = {};

    var STRING_KEYS = {
        errorLoadingStudent: 'dashboard_error_loading_student',
        failedLoadingStudent: 'dashboard_failed_loading_student',
        typingSpeedUnavailable: 'dashboard_typing_speed_unavailable',
        wpmVsClassAverage: 'dashboard_wpm_vs_class_average',
        unusualWordTitle: 'dashboard_unusual_word_title',
        durationSeconds: 'dashboard_duration_seconds',
        durationMinutes: 'dashboard_duration_minutes',
        typingSpeedWpmChart: 'dashboard_typing_speed_wpm_chart',
        classAverage: 'dashboard_class_average',
        wordsPerMinuteAxis: 'dashboard_words_per_minute_axis',
        timeSpentMinutesChart: 'dashboard_time_spent_minutes_chart',
        minutesAxis: 'dashboard_minutes_axis',
        studentFallback: 'dashboard_student_fallback',
    };

    /**
     * Load every string in STRING_KEYS.
     *
     * @returns {Promise} Resolves with an object of the same shape as STRING_KEYS.
     */
    function loadStrings() {
        var names = Object.keys(STRING_KEYS);
        return Str.get_strings(names.map(function(name) {
            return {key: STRING_KEYS[name], component: 'tiny_authory_tech'};
        })).then(function(strings) {
            var loaded = {};
            names.forEach(function(name, i) {
                loaded[name] = strings[i];
            });
            return loaded;
        });
    }

    /**
     * Highlight a word element in the essay: scroll to it and flash the animation.
     *
     * @param {string} elementId
     */
    function highlightElement(elementId) {
        var el = document.getElementById(elementId);
        if (!el) {
            return;
        }
        el.scrollIntoView({behavior: 'smooth', block: 'center'});
        el.classList.add('flash');
        setTimeout(function() {
            el.classList.remove('flash');
        }, 1500);
    }

    /**
     * Replace a student panel's contents with an error message.
     *
     * @param {Element} panel
     * @param {string} message
     */
    function showPanelError(panel, message) {
        var alert = document.createElement('div');
        alert.className = 'alert alert-danger m-3';
        alert.textContent = message;
        panel.replaceChildren(alert);
    }

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
        wrapEl.appendChild(panel);

        Templates.render('tiny_authory_tech/assign_dashboard_student_loading', {fullname: student.fullname})
            .then(function(html) {
                if (panel.dataset.rendered !== '1') {
                    panel.innerHTML = html;
                }
                return true;
            }).catch(function(error) {
                window.console.error('Failed to render template:', error);
            });
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
            var stats;
            try {
                stats = JSON.parse(json);
            } catch (e) {
                showPanelError(panel, STRINGS.errorLoadingStudent);
                return;
            }
            renderStudentPanel(panel, stats, enableReplay).catch(function(error) {
                window.console.error('Failed to render student panel:', error);
                showPanelError(panel, STRINGS.errorLoadingStudent);
            });
        }).fail(function() {
            showPanelError(panel, STRINGS.failedLoadingStudent);
        });
    }

    /**
     * Render the full student panel with metrics and essay.
     * @param {Element} panel
     * @param {Object}  stats        Response from authory_tech_get_student_stats
     * @param {boolean} enableReplay Whether the replay feature is enabled
     * @returns {Promise} Resolves once the panel is in the DOM.
     */
    function renderStudentPanel(panel, stats, enableReplay) {
        var userid         = panel.dataset.userid || '';
        // stats_available is false when type-server couldn't compute real stats
        // (e.g. its database is unreachable) — resolveTypingSpeedDisplay renders
        // "unavailable" in that case rather than a misleading 0.
        var typingSpeed   = DashboardMetrics.resolveTypingSpeedDisplay(stats);
        var units         = {seconds: STRINGS.durationSeconds, minutes: STRINGS.durationMinutes};
        var unusualWords  = stats.unusual_words || [];
        var pastedTexts   = stats.pasted_texts  || [];
        var submText      = stats.submission_text || '';
        var filename      = stats.filename || '';
        var showReplay    = !!(enableReplay && filename);

        var context = {
            fullname: panel.dataset.fullname || '',
            typingspeed: {
                value: typingSpeed.displayValue,
                progresspct: typingSpeed.progressPct,
                comparisonclass: typingSpeed.comparisonClass,
                comparisontext: typingSpeed.statsAvailable
                    ? STRINGS.wpmVsClassAverage
                        .replace('{diff}', typingSpeed.wpmDiffFormatted)
                        .replace('{avg}', typingSpeed.classAvgWpm)
                    : STRINGS.typingSpeedUnavailable,
            },
            hasunusualwords: unusualWords.length > 0,
            unusualwords: unusualWords.map(function(word, i) {
                return {target: 'unusual-' + i, word: word};
            }),
            haspastes: pastedTexts.length > 0,
            pastes: pastedTexts.map(function(text, i) {
                return {
                    target: 'pasted-' + i,
                    number: i + 1,
                    preview: text.length > 80 ? text.substring(0, 80) + '…' : text,
                };
            }),
            essay: submText ? {
                html: buildEssayHtml(submText, unusualWords, pastedTexts),
                wordcount: submText.split(/\s+/).filter(Boolean).length,
                typingtime: DashboardMetrics.formatDuration(stats.typing_duration_seconds || 0, units),
                sessiontime: DashboardMetrics.formatDuration(stats.duration_seconds || 0, units),
            } : null,
            // Containers must exist in the DOM before Replay is instantiated.
            replay: showReplay ? {userid: userid} : null,
        };

        return Templates.renderForPromise('tiny_authory_tech/assign_dashboard_student_panel', context)
            .then(function(result) {
                panel.dataset.rendered = '1';
                Templates.replaceNodeContents(panel, result.html, result.js);
                if (showReplay) {
                    new Replay('content' + userid, filename, 10, false, 'player_' + userid);
                }
                return panel;
            });
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
                '(^|[^a-zA-ZÀ-ž\x01\x02])(' + esc + ')([^a-zA-ZÀ-ž\x01\x02]|$)',
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
            var title = STRINGS.unusualWordTitle.replace('{$a}', function() {
                return um.word;
            });
            marked = marked.split(um.marker).join(
                '<span id="' + um.id + '" class="highlight-unusual" title="'
                + escapeHtml(title) + '">' + um.matched + '</span>'
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

            // Unusual-word tags and "go to text" links in a student's panel.
            $(document).on('click', '[data-highlight-target]', function(e) {
                e.preventDefault();
                highlightElement(this.dataset.highlightTarget);
            });

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

            var stringsCall = loadStrings();
            var calls = Ajax.call([
                {methodname: 'authory_tech_get_assign_dashboard_students', args: {cmid: cmid}},
                {methodname: 'authory_tech_get_assign_overview_stats',     args: {cmid: cmid}},
            ]);

            $.when(stringsCall, calls[0], calls[1]).done(function(strings, studentsJson, overviewJson) {
                STRINGS = strings;

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
                    avgTimeEl.textContent = DashboardMetrics.formatDuration(overview.class_avg_duration_seconds || 0, {
                        seconds: STRINGS.durationSeconds,
                        minutes: STRINGS.durationMinutes,
                    });
                }

                // Charts — resolve names from Moodle students list
                var statsStudents = overview.students || [];
                var names  = statsStudents.map(function(s) {
                    return nameMap[s.person_id] || (STRINGS.studentFallback + ' ' + s.person_id);
                });
                var speeds = statsStudents.map(function(s) { return Math.round(s.avg_wpm); });
                // Minutes, not seconds, and kept as a float — the chart displays
                // fractional minutes (e.g. "10,2" in locales using a comma decimal
                // separator) via formatMinutesLocale() rather than whole seconds.
                var times  = statsStudents.map(function(s) { return (s.duration_seconds || 0) / 60; });

                buildCharts(names, speeds, Math.round(overview.class_avg_wpm), times);

            }).fail(function(err) {
                window.console.error('Failed to load dashboard data:', err);
            });

        }
    };

    /**
     * Format a number of minutes using the page's current language (so the
     * decimal separator matches — e.g. "10,2" in ca/es, "10.2" in en).
     *
     * @param {number} value
     * @returns {string}
     */
    function formatMinutesLocale(value) {
        // toLocaleString needs a valid BCP-47 tag (hyphens); language pack folder
        // names like ca_valencia use an underscore, so normalize it. Still wrapped
        // in try/catch as a last resort — an invalid tag must never break the
        // whole chart render.
        var lang = (document.documentElement.lang || 'en').replace('_', '-');
        try {
            return Number(value).toLocaleString(lang, {minimumFractionDigits: 1, maximumFractionDigits: 1});
        } catch (e) {
            return Number(value).toFixed(1);
        }
    }

    /**
     * Build typing-speed and time-spent charts.
     *
     * @param {string[]} names   Student display names
     * @param {number[]} speeds  WPM per student
     * @param {number}   avg     Class average WPM
     * @param {number[]} times   Time spent in minutes (fractional) per student
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
                            label: STRINGS.typingSpeedWpmChart,
                            data: speeds,
                            backgroundColor: speedColors,
                            borderColor: speedBorders,
                            borderWidth: 2,
                        },
                        {
                            label: STRINGS.classAverage,
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
                    scales: {y: {beginAtZero: true, title: {display: true, text: STRINGS.wordsPerMinuteAxis}}}
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
                            label: STRINGS.timeSpentMinutesChart,
                            data: times,
                            backgroundColor: timeColors,
                            borderColor: timeBorders,
                            borderWidth: 2,
                        },
                        {
                            label: STRINGS.classAverage,
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
                    plugins: {
                        legend: {display: true, position: 'top', labels: {usePointStyle: true}},
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + formatMinutesLocale(context.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {display: true, text: STRINGS.minutesAxis},
                            ticks: {callback: function(value) {
                                return formatMinutesLocale(value);
                            }}
                        }
                    }
                }
            });
        }
    }

});
