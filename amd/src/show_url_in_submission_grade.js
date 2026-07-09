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
 * @module     tiny_authory_tech/show_url_in_submission_grade
 * @category TinyMCE Editor
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author kuldeep singh <mca.kuldeep.sekhon@gmail.com>
 */

define(["jquery", "core/ajax", "core/str", "core/templates", "./replay", "./analytic_button", "./analytic_events",
    "./replay_button"], function(
    $,
    AJAX,
    str,
    templates,
    Replay,
    analyticButton,
    AnalyticEvents,
    replayButton,
) {
    const replayInstances = {};
    // eslint-disable-next-line
    window.video_playback = function (mid, filepath) {
        if (filepath !== '') {
            // $("#playback" + mid).show();
            const replay = new Replay(
                'content' + mid,
                filepath,
                10,
                false,
                'player_' + mid
            );
            replayInstances[mid] = replay;
        } else {
            // eslint-disable-next-line
            templates.render('tiny_authory_tech/no_submission').then(html => {
                $('#content' + mid).html(html);
            }).catch(e => window.console.error(e));
        }
        return false;

    };

    var usersTable = {
        init: function(hasApiKey, enableReplay) {
            $(document).ready(function() {
                $('#page-mod-assign-grader').addClass('tiny_authory_tech_mod_assign_grader');
            });
            str
                .get_strings([
                    {key: "field_require", component: "tiny_authory_tech"},
                ])
                .done(function() {
                    usersTable.appendSubmissionDetail(hasApiKey, enableReplay);
                });
        },

        appendSubmissionDetail: function(hasApiKey, enableReplay) {
            $(document).ready(function($) {

                var divElement = $('.path-mod-assign [data-region="grade-panel"]')[0];
                var previousContextId = window.location.href;
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function() {

                        var currentContextId = window.location.href;
                        if (currentContextId !== previousContextId) {
                            window.location.reload();
                            previousContextId = currentContextId;
                        }
                    });
                });
                // Configuration of the observer:
                var config = {childList: true, subtree: true};
                // Start observing the target node for configured mutations
                observer.observe(divElement, config);

                let subUrl = window.location.href;
                let parm = new URL(subUrl);
                let userid = parm.searchParams.get('userid');
                var cmid = parm.searchParams.get('id');

                let args = {id: userid, modulename: "assign", 'cmid': cmid};
                let methodname = 'authory_tech_get_assign_grade_comment';
                let com = AJAX.call([{methodname, args}]);
                com[0].done(function(json) {
                    var data = JSON.parse(json);
                    var filepath = '';
                    if (data.data.filename) {
                        filepath = data.data.filename;
                    }

                    let analyticButtonDiv = document.createElement('div');
                    analyticButtonDiv.classList.add('tiny_authory_tech-grade-panel-card');

                    // Append to body so the fixed badge is outside the grade-panel DOM tree
                    // and cannot disturb Moodle's embedded flex layout.
                    document.body.appendChild(analyticButtonDiv);

                    // Position the badge just below the grading navigation panel.
                    var navPanel = document.querySelector('[data-region="grading-navigation-panel"]');
                    if (navPanel) {
                        analyticButtonDiv.style.top = (navPanel.offsetHeight + 10) + 'px';
                    }

                    $('div[data-region="review-panel"]').addClass('authory_tech_review_panel_path_mod_assign');
                    $('div[data-region="grading-navigation-panel"]')
                        .addClass('authory_tech_grading-navigation-panel_path_mod_assign');
                    $('div[data-region="grade-panel"]').addClass('authory_tech_grade-panel_path_mod_assign');
                    $('div[data-region="grade-actions-panel"]').addClass('authory_tech_grade-actions-panel_path_mod_assign');

                    if (!hasApiKey && enableReplay) {
                        $(analyticButtonDiv).html(replayButton(userid));
                    } else {
                        var dashboardUrl = M.cfg.wwwroot
                            + '/lib/editor/tiny/plugins/authory_tech/assign_dashboard.php'
                            + '?id=' + cmid + '&student=' + userid;
                        analyticButtonDiv.append(analyticButton(data.data.effort_ratio, userid, '', dashboardUrl));

                        // Fetch avg typing speed from type server via PHP proxy.
                        let statsCall = AJAX.call([{
                            methodname: 'authory_tech_get_student_stats',
                            args: {resource_id: parseInt(data.data.file_id), userid: parseInt(userid)},
                        }]);
                        statsCall[0].done(function(json) {
                            try {
                                let stats = JSON.parse(json);
                                let valueEl = document.getElementById('authory-speed-value-' + userid);
                                if (valueEl) {
                                    valueEl.textContent = Math.round(stats.avg_wpm);
                                }
                                let progressEl = document.getElementById('authory-speed-progress-' + userid);
                                if (progressEl) {
                                    progressEl.style.width = Math.min(100, Math.round(stats.avg_wpm)) + '%';
                                }
                                let markEl = document.getElementById('authory-speed-mark-' + userid);
                                if (markEl && stats.class_avg_wpm) {
                                    markEl.style.left = Math.min(100, Math.round(stats.class_avg_wpm)) + '%';
                                    markEl.title = 'Class avg: ' + Math.round(stats.class_avg_wpm) + ' WPM';
                                    markEl.style.display = '';
                                }
                                let diffEl = document.getElementById('authory-speed-diff-' + userid);
                                if (diffEl && stats.wpm_diff !== undefined && stats.wpm_diff !== null) {
                                    let diffVal = parseFloat(stats.wpm_diff);
                                    let absDiff = Math.abs(diffVal);
                                    let sign = diffVal >= 0 ? '+' : '';
                                    diffEl.textContent = sign + diffVal.toFixed(1) + ' WPM compared to class average';
                                    let devClass = absDiff < 5 ? 'deviation-low'
                                        : absDiff < 15 ? 'deviation-medium'
                                        : 'deviation-high';
                                    diffEl.classList.add(devClass);
                                    diffEl.style.display = '';
                                }
                                let timeValueEl = document.getElementById('authory-time-value-' + userid);
                                if (timeValueEl && stats.duration_seconds !== undefined) {
                                    let secs = Math.round(stats.duration_seconds);
                                    let m = Math.floor(secs / 60);
                                    let s = secs % 60;
                                    timeValueEl.textContent = m + 'm ' + (s < 10 ? '0' : '') + s + 's';
                                }
                                let timeProgressEl = document.getElementById('authory-time-progress-' + userid);
                                if (timeProgressEl && stats.class_avg_duration_seconds > 0) {
                                    let pct = Math.min(100, Math.round(
                                        stats.duration_seconds / (stats.class_avg_duration_seconds * 2) * 100
                                    ));
                                    timeProgressEl.style.width = pct + '%';
                                }
                                let timeMarkEl = document.getElementById('authory-time-mark-' + userid);
                                if (timeMarkEl && stats.class_avg_duration_seconds > 0) {
                                    timeMarkEl.style.left = '50%';
                                    let avgSecs = Math.round(stats.class_avg_duration_seconds);
                                    let am = Math.floor(avgSecs / 60);
                                    let as_ = avgSecs % 60;
                                    timeMarkEl.title = 'Class avg: ' + am + 'm ' + (as_ < 10 ? '0' : '') + as_ + 's';
                                    timeMarkEl.style.display = '';
                                }
                                let timeDiffEl = document.getElementById('authory-time-diff-' + userid);
                                if (timeDiffEl && stats.duration_seconds !== undefined
                                        && stats.class_avg_duration_seconds !== undefined) {
                                    let diffSecs = Math.round(stats.duration_seconds - stats.class_avg_duration_seconds);
                                    let absDiff = Math.abs(diffSecs);
                                    let dm = Math.floor(absDiff / 60);
                                    let ds = absDiff % 60;
                                    let diffLabel = (diffSecs < 0 ? '-' : '+') + dm + 'm ' + (ds < 10 ? '0' : '') + ds + 's';
                                    timeDiffEl.textContent = diffLabel + ' compared to class average';
                                    let devClass = absDiff < 60 ? 'deviation-low'
                                        : absDiff < 300 ? 'deviation-medium'
                                        : 'deviation-high';
                                    timeDiffEl.classList.add(devClass);
                                    timeDiffEl.style.display = '';
                                }

                                let wordCountEl = document.getElementById('authory-unusual-words-' + userid);
                                if (wordCountEl) {
                                    let words = stats.unusual_words;
                                    wordCountEl.textContent = Array.isArray(words) ? words.length : 0;
                                }
                                let pasteCountEl = document.getElementById('authory-pasted-texts-' + userid);
                                if (pasteCountEl) {
                                    let pastes = stats.pasted_texts;
                                    pasteCountEl.textContent = Array.isArray(pastes) ? pastes.length : 0;
                                }
                            } catch (e) {
                                window.console.error('Error parsing student stats:', e);
                            }
                        }).fail(function() {
                            let valueEl = document.getElementById('authory-speed-value-' + userid);
                            if (valueEl) {
                                valueEl.textContent = '—';
                            }
                        });
                    }

                    let myEvents = new AnalyticEvents();
                    var context = {
                        tabledata: data.data,
                        formattime: myEvents.formatedTime(data.data),
                        userid: userid,
                        apikey: hasApiKey
                    };

                    myEvents.createModal(userid, context, '', replayInstances);
                    myEvents.analytics(userid, templates, context, '', replayInstances);
                    myEvents.checkDiff(userid, data.data.file_id, '', replayInstances);
                    myEvents.replyWriting(userid, filepath, '', replayInstances);


                });
                return com.usercomment;
            });
        },
    };
    return usersTable;
});


