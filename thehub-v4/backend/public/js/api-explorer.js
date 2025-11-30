function runApi(endpoint) {
  const out = document.getElementById('apiOutput');
  if (!out) return;
  out.textContent = 'Loading...';

  fetch(endpoint)
    .then(r => r.json())
    .then(data => {
      out.textContent = JSON.stringify(data, null, 2);
    })
    .catch(err => {
      out.textContent = 'Error: ' + err;
    });
}
