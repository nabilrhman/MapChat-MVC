// All requests to the server go through axios. The PHP side reads $_POST,
// so data is sent as a regular form post rather than JSON
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// A request with no answer by then fails rather than hang for good; the
// polling and the people list try again on their next round
axios.defaults.timeout = 15000;

// When the session has ended, go to the login page instead of showing the
// error in place
axios.interceptors.response.use(null, (error) => {
    if (error.response?.status === 401) {
        window.location.href = '/login';
    }
    return Promise.reject(error);
});

function postForm(url, data = {}) {
    return axios.post(url, new URLSearchParams(data));
}

// The message the server gave for a refused request, if any
function errorMessageOf(error, fallback) {
    return error?.response?.data?.error || fallback;
}

// The promise's answer, or a failure once the limit (in ms) has passed:
// for a wait that might never end
function withTimeLimit(promise, limit) {
    let timer;
    const timeout = new Promise((resolve, reject) => {
        timer = setTimeout(() => reject(new Error('Took too long')), limit);
    });
    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}
