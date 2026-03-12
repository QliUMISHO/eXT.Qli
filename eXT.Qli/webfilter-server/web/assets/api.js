window.WFAPI = {
    async request(url, method = 'GET', data = null) {
        const options = {
            method,
            headers: {}
        };

        if (data !== null) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }

        const res = await fetch(url, options);
        const text = await res.text();
        let json = {};

        try {
            json = JSON.parse(text);
        } catch (e) {
            json = { status: 'error', message: text || 'Invalid server response' };
        }

        if (!res.ok) {
            throw json;
        }

        return json;
    },

    get(url) {
        return this.request(url, 'GET');
    },

    post(url, data) {
        return this.request(url, 'POST', data);
    }
};