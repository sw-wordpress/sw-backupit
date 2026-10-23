(function () {
  'use strict';
  var body = document.body;
  if (!body.getAttribute('data-run')) { return; }
  var csrf = body.getAttribute('data-csrf') || '';
  var status = document.getElementById('status');
  var bar = document.getElementById('bar');
  var log = document.getElementById('log');
  var finish = document.getElementById('finish');
  var phases = { verify: 5, files: 50, database: 80, migrate: 92, config: 97, done: 100 };
  var failures = 0;

  function show(text) { status.textContent = text; }

  function tick() {
    fetch('?a=tick', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        failures = 0;
        bar.value = phases[data.phase] || bar.value;
        if (data.status === 'continue') {
          show('Phase: ' + data.phase + ' – files restored: ' + data.files + ' (' + data.bytes + ')');
          window.setTimeout(tick, 200);
        } else if (data.status === 'completed') {
          bar.value = 100;
          show('The restore completed successfully. Files restored: ' + data.files + ' (' + data.bytes + ').');
          finish.hidden = false;
        } else {
          show('The restore FAILED in phase "' + data.phase + '". It can be resumed: reload this page to continue.');
          log.textContent = (data.messages || []).join('\n');
        }
      })
      .catch(function () {
        failures += 1;
        if (failures > 5) { show('Connection lost. Reload this page to resume the restore.'); return; }
        window.setTimeout(tick, 1500 * failures);
      });
  }
  tick();
}());
