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
 * Module that creates an analytics button with an icon and text.
 * The button displays analytics information for a specific user and question.
 *
 * @module     tiny_authory_tech/analytic_button
 * @copyright  2024 CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
  const analyticButton = (effort, userid, questionid = "", dashboardUrl = "") => { // eslint-disable-line no-unused-vars
    const anchor = document.createElement("a");
    anchor.href = dashboardUrl || "#";
    anchor.target = '_blank';
    anchor.rel = 'noopener noreferrer';
    anchor.id = "authory-dash-link-" + userid + questionid;
    anchor.classList.add(
      "d-inline-flex",
      "justify-content-center",
      'text-decoration-none'
    );

    const card = document.createElement('div');
    card.className = 'tiny_authory_tech-metric-card';

    // Branded header strip
    const brandHeader = document.createElement('div');
    brandHeader.className = 'tiny_authory_tech-card-brand';
    const brandLogo = document.createElement('img');
    brandLogo.className = 'tiny_authory_tech-brand-logo';
    // eslint-disable-next-line no-undef
    brandLogo.src = M.cfg.wwwroot + '/lib/editor/tiny/plugins/authory_tech/pix/authory_tech_logo.svg';
    brandLogo.alt = 'Authory';
    brandHeader.appendChild(brandLogo);
    card.appendChild(brandHeader);

    const content = document.createElement('div');
    content.className = 'tiny_authory_tech-metric-content';

    // Speed: title + big number + progress bar + comparison
    const title = document.createElement('h3');
    title.textContent = 'Avg Speed';
    content.appendChild(title);

    const metricValue = document.createElement('div');
    metricValue.className = 'tiny_authory_tech-metric-value';

    const bigNumber = document.createElement('span');
    bigNumber.className = 'tiny_authory_tech-big-number';
    bigNumber.id = 'authory-speed-value-' + userid + questionid;
    bigNumber.textContent = '…';

    metricValue.appendChild(bigNumber);
    content.appendChild(metricValue);

    const progressBar = document.createElement('div');
    progressBar.className = 'tiny_authory_tech-progress-bar';
    const progressFill = document.createElement('div');
    progressFill.className = 'tiny_authory_tech-progress-fill';
    progressFill.id = 'authory-speed-progress-' + userid + questionid;
    progressFill.style.width = '0%';
    progressBar.appendChild(progressFill);
    const progressMark = document.createElement('div');
    progressMark.className = 'tiny_authory_tech-progress-mark';
    progressMark.id = 'authory-speed-mark-' + userid + questionid;
    progressMark.style.display = 'none';
    progressBar.appendChild(progressMark);
    content.appendChild(progressBar);

    const diff = document.createElement('p');
    diff.className = 'tiny_authory_tech-comparison';
    diff.id = 'authory-speed-diff-' + userid + questionid;
    diff.style.display = 'none';
    content.appendChild(diff);

    // Time on Task section
    const timeTitle = document.createElement('h3');
    timeTitle.textContent = 'Time on Task';
    content.appendChild(timeTitle);

    const timeMetricValue = document.createElement('div');
    timeMetricValue.className = 'tiny_authory_tech-metric-value';

    const timeBigNumber = document.createElement('span');
    timeBigNumber.className = 'tiny_authory_tech-big-number';
    timeBigNumber.id = 'authory-time-value-' + userid + questionid;
    timeBigNumber.textContent = '…';

    timeMetricValue.appendChild(timeBigNumber);
    content.appendChild(timeMetricValue);

    const timeProgressBar = document.createElement('div');
    timeProgressBar.className = 'tiny_authory_tech-progress-bar';
    const timeProgressFill = document.createElement('div');
    timeProgressFill.className = 'tiny_authory_tech-progress-fill';
    timeProgressFill.id = 'authory-time-progress-' + userid + questionid;
    timeProgressFill.style.width = '0%';
    timeProgressBar.appendChild(timeProgressFill);
    const timeProgressMark = document.createElement('div');
    timeProgressMark.className = 'tiny_authory_tech-progress-mark';
    timeProgressMark.id = 'authory-time-mark-' + userid + questionid;
    timeProgressMark.style.display = 'none';
    timeProgressBar.appendChild(timeProgressMark);
    content.appendChild(timeProgressBar);

    const timeDiff = document.createElement('p');
    timeDiff.className = 'tiny_authory_tech-comparison';
    timeDiff.id = 'authory-time-diff-' + userid + questionid;
    timeDiff.style.display = 'none';
    content.appendChild(timeDiff);

    // Divider
    const divider = document.createElement('hr');
    divider.className = 'tiny_authory_tech-card-divider';
    content.appendChild(divider);

    // Secondary stats row
    const statsRow = document.createElement('div');
    statsRow.className = 'tiny_authory_tech-stats-row';

    const makeStatItem = (label, id) => {
        const item = document.createElement('div');
        item.className = 'tiny_authory_tech-stat-item';
        const num = document.createElement('span');
        num.className = 'tiny_authory_tech-stat-number';
        num.id = id;
        num.textContent = '…';
        const lbl = document.createElement('span');
        lbl.className = 'tiny_authory_tech-stat-label';
        lbl.textContent = label;
        item.appendChild(num);
        item.appendChild(lbl);
        return item;
    };

    statsRow.appendChild(makeStatItem('Unusual Words', 'authory-unusual-words-' + userid + questionid));
    statsRow.appendChild(makeStatItem('Pasted Texts', 'authory-pasted-texts-' + userid + questionid));
    content.appendChild(statsRow);

    card.appendChild(content);
    anchor.appendChild(card);

    return anchor;
  };

  return analyticButton;
});
