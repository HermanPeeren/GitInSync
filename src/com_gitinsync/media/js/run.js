/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

/**
 * Runs a long job in portions: posts to the step URL until the answer says it is done.
 * Each portion stays within the server's time limit, so no command line is needed.
 *
 * <div class="gitinsync-run" data-url="..." data-token="..." data-done-url="...">
 */
(() => {
  'use strict';

  const start = (element) => {
    const log = element.querySelector('.gitinsync-run-log');
    const status = element.querySelector('.gitinsync-run-status');
    const bar = element.querySelector('.progress-bar');
    const doneUrl = element.dataset.doneUrl || '';
    let portions = 0;

    const write = (text, error = false) => {
      const line = document.createElement('li');
      line.textContent = text;

      if (error) {
        line.classList.add('text-danger');
      }

      log.appendChild(line);
    };

    const step = async () => {
      const body = new FormData();
      body.append(element.dataset.token, '1');

      let answer;

      try {
        const response = await fetch(element.dataset.url, { method: 'POST', body, credentials: 'same-origin' });
        answer = await response.json();
      } catch (e) {
        status.textContent = Joomla.Text._('COM_GITINSYNC_RUN_FAILED');
        write(String(e), true);
        return;
      }

      const data = answer.data || {};
      portions += 1;
      bar.style.width = `${Math.min(95, portions * 10)}%`;

      if (data.message || answer.message) {
        write(data.message || answer.message, Boolean(data.error) || answer.success === false);
      }

      if (data.done || answer.success === false) {
        bar.style.width = '100%';
        bar.classList.remove('progress-bar-animated');
        status.textContent = data.error || answer.success === false
          ? Joomla.Text._('COM_GITINSYNC_RUN_FAILED')
          : Joomla.Text._('COM_GITINSYNC_RUN_DONE');

        if (data.operation) {
          window.location.href = `index.php?option=com_gitinsync&view=operation&id=${Number(data.operation)}`;
        } else if (doneUrl && !data.error && answer.success !== false) {
          window.setTimeout(() => { window.location.href = doneUrl; }, 1500);
        }

        return;
      }

      step();
    };

    step();
  };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.gitinsync-run').forEach(start);
  });
})();
