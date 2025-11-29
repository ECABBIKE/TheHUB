async function runApi(endpoint) {
    const output = document.getElementById('apiOutput');
    output.textContent = 'Loading...';

    try {
        const response = await fetch(endpoint);
        const data = await response.json();
        output.textContent = JSON.stringify(data, null, 2);
    } catch (err) {
        output.textContent = 'Error: ' + err;
    }
}
