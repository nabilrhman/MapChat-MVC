// All requests to the server go through axios. The PHP side reads $_POST,
// so data is sent as a regular form post rather than JSON.
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// When the session has ended, go to the login page instead of showing the
// error in place
axios.interceptors.response.use(null, function (error) {
    if (error.response && error.response.status === 401) {
        window.location.href = '/login';
    }
    return Promise.reject(error);
});

function postForm(url, data) {
    return axios.post(url, new URLSearchParams(data || {}));
}

// The message the server gave for a refused request, if any
function getErrorMessage(error, fallback) {
    const data = error?.response?.data;
    return data?.error || fallback;
}
