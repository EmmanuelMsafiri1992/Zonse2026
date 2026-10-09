// Passkey sign-in and registration over plain WebAuthn, talking to the laravel/passkeys routes.

const toBuffer = (value) => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=');
    return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0)).buffer;
};

const toBase64Url = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

const csrf = () => document.querySelector('meta[name=csrf-token]')?.content;

const request = (url, options = {}) => fetch(url, {
    credentials: 'same-origin',
    ...options,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
});

const credentialJson = (credential) => {
    const response = {};
    for (const key of ['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle']) {
        if (credential.response[key]) response[key] = toBase64Url(credential.response[key]);
    }
    if (typeof credential.response.getTransports === 'function') response.transports = credential.response.getTransports();

    return {
        id: credential.id,
        rawId: toBase64Url(credential.rawId),
        type: credential.type,
        authenticatorAttachment: credential.authenticatorAttachment ?? null,
        clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
        response,
    };
};

const errorMessage = async (response, fallback) => {
    try {
        const data = await response.json();
        return data.message || Object.values(data.errors || {})[0]?.[0] || fallback;
    } catch (e) {
        return fallback;
    }
};

export const passkeysSupported = () => typeof window.PublicKeyCredential === 'function' && window.isSecureContext;

/** Asks the browser for a passkey, then signs in with it. */
export async function signInWithPasskey({ optionsUrl, loginUrl, remember = false }) {
    const optionsResponse = await request(optionsUrl);
    if (!optionsResponse.ok) throw new Error(await errorMessage(optionsResponse, 'Passkey sign-in is not available right now.'));
    const { options } = await optionsResponse.json();

    options.challenge = toBuffer(options.challenge);
    (options.allowCredentials || []).forEach((item) => { item.id = toBuffer(item.id); });

    const credential = await navigator.credentials.get({ publicKey: options });
    const response = await request(loginUrl, { method: 'POST', body: JSON.stringify({ credential: credentialJson(credential), remember }) });
    if (!response.ok) throw new Error(await errorMessage(response, 'That passkey was not accepted.'));

    const data = await response.json().catch(() => ({}));
    window.location.href = data.redirect || '/dashboard';
}

/** Creates a passkey on this device and saves it to the signed-in account. Returns false if the password must be confirmed first. */
export async function registerPasskey({ optionsUrl, storeUrl, confirmUrl, name }) {
    const optionsResponse = await request(optionsUrl);
    if (optionsResponse.status === 423) {
        window.location.href = confirmUrl;
        return false;
    }
    if (!optionsResponse.ok) throw new Error(await errorMessage(optionsResponse, 'Could not start adding a passkey.'));
    const { options } = await optionsResponse.json();

    options.challenge = toBuffer(options.challenge);
    options.user.id = toBuffer(options.user.id);
    (options.excludeCredentials || []).forEach((item) => { item.id = toBuffer(item.id); });

    const credential = await navigator.credentials.create({ publicKey: options });
    const response = await request(storeUrl, { method: 'POST', body: JSON.stringify({ name, credential: credentialJson(credential) }) });
    if (!response.ok) throw new Error(await errorMessage(response, 'The passkey could not be saved.'));

    return true;
}

/** Alpine component behind the "Sign in with a passkey" button. */
export const passkeyLogin = (optionsUrl, loginUrl) => ({
    supported: passkeysSupported(),
    busy: false,
    error: null,
    async signIn() {
        this.busy = true;
        this.error = null;
        try {
            await signInWithPasskey({ optionsUrl, loginUrl, remember: document.getElementById('remember')?.checked ?? false });
        } catch (e) {
            this.error = e.name === 'NotAllowedError' ? 'Passkey sign-in was cancelled.' : e.message;
            this.busy = false;
        }
    },
});

/** Alpine component behind "Add a passkey" on the profile page. */
export const passkeyRegister = (optionsUrl, storeUrl, confirmUrl) => ({
    supported: passkeysSupported(),
    name: '',
    busy: false,
    error: null,
    async add() {
        this.busy = true;
        this.error = null;
        try {
            if (await registerPasskey({ optionsUrl, storeUrl, confirmUrl, name: this.name.trim() || 'Passkey' })) {
                window.zonseo.reload();
            }
        } catch (e) {
            this.error = e.name === 'NotAllowedError' ? 'Adding the passkey was cancelled.' : e.message;
            this.busy = false;
        }
    },
});
