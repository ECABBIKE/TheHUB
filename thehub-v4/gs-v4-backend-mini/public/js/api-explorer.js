function runApi(endpoint) {

    const output = document.getElementById('apiOutput');

    output.textContent = 'Loading...';

    // BASE URL FIX
    const baseUrl = window.location.origin +
        '/thehub/thehub-v4/gs-v4-backend-mini/public';

    const url = baseUrl + endpoint;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            output.textContent = JSON.stringify(data, null, 2);
        })
        .catch(err => {
            output.textContent = 'Error: ' + err;
        });
}
