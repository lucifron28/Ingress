import { initializeApp } from 'firebase/app';
import {
    browserSessionPersistence,
    createUserWithEmailAndPassword,
    initializeAuth,
    onAuthStateChanged,
    reload,
    sendEmailVerification,
    sendPasswordResetEmail,
    signInWithEmailAndPassword,
    signOut,
    updateProfile,
} from 'firebase/auth';

const root = document.querySelector('[data-student-auth]');

if (root) {
    const message = root.querySelector('[data-message]');
    const panels = [...root.querySelectorAll('[data-panel]')];
    const verificationPanel = root.querySelector('[data-verification]');
    const continuePanel = root.querySelector('[data-continue-panel]');
    const buttons = [...root.querySelectorAll('button')];
    let currentUser = null;
    let auth = null;
    let exchangePending = false;

    function showMessage(text, kind = 'notice') {
        message.textContent = text;
        message.className = `notice ${kind}`;
        message.hidden = false;
    }

    function hideMessage() {
        message.hidden = true;
        message.textContent = '';
    }

    function showPanel(name) {
        for (const panel of panels) {
            panel.hidden = panel.dataset.panel !== name;
        }

        verificationPanel.hidden = true;
        continuePanel.hidden = true;
        for (const tab of root.querySelectorAll('[role="tab"]')) {
            tab.setAttribute('aria-selected', String(tab.dataset.show === name));
        }
        hideMessage();
    }

    function actionSettings(query) {
        return { url: new URL(`/student/auth?${query}`, window.location.origin).toString() };
    }

    function setBusy(isBusy) {
        for (const button of buttons) {
            button.disabled = isBusy;
        }
    }

    function getFriendlyError(error, fallback) {
        switch (error?.code) {
            case 'auth/email-already-in-use':
                return 'An account already uses this email. Sign in or reset its password.';
            case 'auth/weak-password':
                return 'Choose a stronger password with at least 12 characters.';
            case 'auth/too-many-requests':
                return 'Too many attempts. Wait a while, then try again.';
            case 'auth/invalid-credential':
            case 'auth/user-not-found':
            case 'auth/wrong-password':
                return 'The email or password could not be confirmed.';
            case 'auth/operation-not-allowed':
                return 'Email and password sign-in is not enabled. Contact the Ingress administrator.';
            default:
                return fallback;
        }
    }

    async function exchangeFirebaseSession(user) {
        if (exchangePending) {
            return;
        }

        if (!user.emailVerified) {
            verificationPanel.hidden = false;
            continuePanel.hidden = true;
            showMessage('Verify the email address connected to your account before signing in to Ingress.', 'error');
            return;
        }

        exchangePending = true;
        setBusy(true);

        try {
            const idToken = await user.getIdToken(true);
            const response = await fetch('/student/session', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ id_token: idToken }),
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(result.message || 'Student sign-in could not be completed. Try again.');
            }

            try {
                await signOut(auth);
            } catch {
                // Laravel has already issued its session; Firebase sign-out is only cleanup.
            }
            window.location.assign(result.redirect || '/student/dashboard');
        } catch (error) {
            showMessage(error.message || 'Student sign-in could not be completed. Try again.', 'error');
        } finally {
            exchangePending = false;
            setBusy(false);
        }
    }

    if (root.dataset.configured !== 'true') {
        setBusy(true);
    } else {
        try {
            const firebaseConfig = JSON.parse(root.dataset.firebaseConfig || '{}');
            const firebaseApp = initializeApp(firebaseConfig);
            auth = initializeAuth(firebaseApp, { persistence: browserSessionPersistence });

            onAuthStateChanged(auth, async (user) => {
                currentUser = user;
                const wantsAutomaticExchange = new URLSearchParams(window.location.search).get('verified') === '1';

                if (!user) {
                    if (wantsAutomaticExchange) {
                        showMessage('Your email may be verified. Sign in to continue to Ingress.');
                    }

                    return;
                }

                if (wantsAutomaticExchange) {
                    try {
                        await reload(user);
                    } catch {
                        showMessage('Firebase could not refresh your email status. Sign in again or retry.', 'error');
                        return;
                    }
                }

                panels.forEach((panel) => { panel.hidden = true; });
                if (user.emailVerified) {
                    continuePanel.hidden = false;
                    verificationPanel.hidden = true;
                    if (wantsAutomaticExchange) {
                        await exchangeFirebaseSession(user);
                    }
                } else {
                    verificationPanel.hidden = false;
                    continuePanel.hidden = true;
                }
            });
        } catch {
            showMessage('Student sign-in could not be initialized. Contact the Ingress administrator.', 'error');
        }
    }

    for (const trigger of root.querySelectorAll('[data-show]')) {
        trigger.addEventListener('click', () => showPanel(trigger.dataset.show));
    }

    root.querySelector('[data-panel="sign-in"]').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const email = form.elements.email.value.trim().toLowerCase();

        setBusy(true);
        try {
            const credential = await signInWithEmailAndPassword(auth, email, form.elements.password.value);
            currentUser = credential.user;
            if (!credential.user.emailVerified) {
                verificationPanel.hidden = false;
                panels.forEach((panel) => { panel.hidden = true; });
                showMessage('Verify the email address connected to your account before signing in to Ingress.', 'error');
                return;
            }
            await exchangeFirebaseSession(credential.user);
        } catch (error) {
            showMessage(getFriendlyError(error, 'The email or password could not be confirmed.'), 'error');
        } finally {
            setBusy(false);
        }
    });

    root.querySelector('[data-panel="create"]').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const name = form.elements.name.value.trim();
        const email = form.elements.email.value.trim().toLowerCase();
        const password = form.elements.password.value;

        if (name.length === 0 || password.length < 12 || password.length > 128) {
            showMessage('Enter your name and a password between 12 and 128 characters.', 'error');
            return;
        }
        if (password !== form.elements.password_confirmation.value) {
            showMessage('The password confirmation does not match.', 'error');
            return;
        }

        setBusy(true);
        try {
            const credential = await createUserWithEmailAndPassword(auth, email, password);
            currentUser = credential.user;
            await updateProfile(credential.user, { displayName: name });
            await sendEmailVerification(credential.user, actionSettings('verified=1'));
            panels.forEach((panel) => { panel.hidden = true; });
            verificationPanel.hidden = false;
            showMessage('Firebase sent a verification email. Follow its link, then return here.', 'success');
        } catch (error) {
            showMessage(getFriendlyError(error, 'The account could not be created or the verification email could not be sent.'), 'error');
        } finally {
            setBusy(false);
        }
    });

    root.querySelector('[data-panel="reset"]').addEventListener('submit', async (event) => {
        event.preventDefault();
        const email = event.currentTarget.elements.email.value.trim().toLowerCase();

        setBusy(true);
        try {
            await sendPasswordResetEmail(auth, email, actionSettings('reset=sent'));
            showMessage('If an account exists for this address, Firebase sent password reset instructions.', 'success');
        } catch {
            showMessage('If an account exists for this address, Firebase sent password reset instructions.', 'success');
        } finally {
            setBusy(false);
        }
    });

    for (const button of root.querySelectorAll('[data-resend]')) {
        button.addEventListener('click', async () => {
            if (!currentUser) {
                showMessage('Sign in again to resend the verification email.', 'error');
                return;
            }

            setBusy(true);
            try {
                await sendEmailVerification(currentUser, actionSettings('verified=1'));
                showMessage('Firebase sent another verification email.', 'success');
            } catch (error) {
                showMessage(getFriendlyError(error, 'The verification email could not be sent. Try again later.'), 'error');
            } finally {
                setBusy(false);
            }
        });
    }

    for (const button of root.querySelectorAll('[data-continue]')) {
        button.addEventListener('click', async () => {
            if (!currentUser) {
                showPanel('sign-in');
                showMessage('Sign in with your verified email address to continue.', 'error');
                return;
            }

            setBusy(true);
            try {
                await reload(currentUser);
                await exchangeFirebaseSession(currentUser);
            } catch (error) {
                showMessage(getFriendlyError(error, 'Firebase could not confirm the verified address yet. Try again.'), 'error');
            } finally {
                setBusy(false);
            }
        });
    }

    const resetWasRequested = new URLSearchParams(window.location.search).get('reset') === 'sent';
    if (resetWasRequested) {
        showMessage('If an account exists for this address, Firebase sent password reset instructions.', 'success');
    }
}
