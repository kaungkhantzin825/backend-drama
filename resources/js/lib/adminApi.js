import { usePage } from '@inertiajs/vue3'

/**
 * Tiny wrapper around fetch() for the /admin/api endpoints.
 *
 * Adds the CSRF header Inertia expects, unwraps the { success, message, data }
 * envelope, and turns a failed request into a thrown Error with the server's
 * message — so screens can just try/catch and show `error.message`.
 */
export function useAdminApi() {
    const page = usePage()

    async function request(path, { method = 'GET', body } = {}) {
        const response = await fetch(path, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': page.props.csrf_token,
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        })

        const payload = await response.json().catch(() => ({}))

        if (!response.ok || payload.success === false) {
            throw new Error(payload.message || `Request failed (${response.status})`)
        }

        return payload
    }

    return {
        get: (path) => request(path),
        post: (path, body) => request(path, { method: 'POST', body }),
        patch: (path, body) => request(path, { method: 'PATCH', body }),
        put: (path, body) => request(path, { method: 'PUT', body }),
        del: (path) => request(path, { method: 'DELETE' }),
    }
}
