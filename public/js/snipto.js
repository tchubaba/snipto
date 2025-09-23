import QRCode from "qrcode";
function sniptoComponent() {
  return {
    slug: null,
    // Unique identifier of the Snipto (URL slug)
    key: null,
    // Derived AES-CBC CryptoKey object
    iv: null,
    // Initialization Vector (hex string)
    payload: "",
    // Decrypted plaintext payload
    expires_at: "",
    // Expiry time of this Snipto (from server)
    views_remaining: 0,
    // How many times this Snipto can still be viewed
    loading: true,
    // UI state: true while fetching/decrypting
    showPayload: false,
    // UI toggle: decrypted content visible
    showForm: false,
    // UI toggle: show new snipto form
    showSuccess: false,
    // UI toggle: show success screen
    errorMessage: "",
    // Error messages for user
    userInput: "",
    // Text entered when creating a new Snipto
    fullUrl: "",
    // Full shareable URL (with key in hash)
    showToast: false,
    // Toast notification state
    calledInit: false,
    // Prevent multiple init calls
    // ------------------------------
    // Initialization (view mode)
    // ------------------------------
    /**
     * Fetch an existing Snipto (if slug present), derive key, and decrypt payload.
     * Handles error cases (missing key, invalid ciphertext, expired/deleted sniptos).
     */
    async init() {
      this.slug = this.$el.dataset.slug || "";
      if (this.calledInit) return;
      this.calledInit = true;
      if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
        document.documentElement.classList.add("dark");
      }
      if (!this.slug) {
        this.showForm = true;
        this.loading = false;
        this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
        return;
      }
      try {
        const res = await fetch(`/api/snipto/${this.slug}`, {
          method: "GET",
          headers: { "Accept": "application/json" }
        });
        if (res.status === 404) {
          this.showForm = true;
          this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
          return;
        } else if (res.status === 429) {
          this.errorMessage = "Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.";
          return;
        }
        if (!res.ok) throw new Error("Error fetching snipto");
        const data = await res.json();
        const shortSecret = new URLSearchParams(window.location.hash.substring(1)).get("k");
        if (!shortSecret) {
          this.errorMessage = "Missing decryption key in URL.";
          return;
        }
        this.iv = data.iv;
        this.key = await this.deriveKey(shortSecret, this.iv);
        let decrypted;
        try {
          decrypted = await this.decryptPayload(data.payload, this.key, this.iv);
        } catch {
          decrypted = "";
        }
        if (!decrypted) {
          this.errorMessage = "Failed to decrypt your snipto. Please check your key.";
          return;
        }
        this.payload = decrypted.trim();
        this.expires_at = data.expires_at;
        this.views_remaining = data.views_remaining - 1;
        this.showPayload = true;
        const hashHex = await this.sha256Hex(new TextEncoder().encode(data.payload));
        await fetch(`/api/snipto/${this.slug}/viewed`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": this.getCsrfToken(),
            "Accept": "application/json"
          },
          body: JSON.stringify({ payload_hash: hashHex }),
          credentials: "same-origin"
        });
      } catch (err) {
        this.errorMessage = err.message;
      } finally {
        this.loading = false;
      }
    },
    // ------------------------------
    // Submission (create mode)
    // ------------------------------
    /**
     * Encrypt and submit a new Snipto to the server.
     * 1. Generate a random short secret (shared only via URL fragment).
     * 2. Generate a random IV (16 bytes).
     * 3. Derive AES key with PBKDF2 (salt = IV).
     * 4. Encrypt user’s plaintext.
     * 5. Send ciphertext + IV (never plaintext, never secret) to server.
     * 6. Show success UI with shareable URL (#k=secret).
     */
    async submitSnipto() {
      if (!this.userInput.trim()) return;
      this.loading = true;
      const shortSecret = this.generateShortSecret(16);
      this.iv = await this.generateRandomBytes(16);
      this.key = await this.deriveKey(shortSecret, this.iv);
      const encrypted = await this.encryptPayload(this.userInput, this.key, this.iv);
      try {
        const res = await fetch("/api/snipto", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": this.getCsrfToken(),
            "Accept": "application/json"
          },
          body: JSON.stringify({
            slug: this.slug,
            payload: encrypted,
            iv: this.iv
          }),
          credentials: "same-origin"
        });
        if (res.status === 429) {
          this.errorMessage = "Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.";
          return;
        }
        const body = await res.json();
        if (!res.ok || !body.success) {
          this.errorMessage = "An error occurred. Please try again.";
          return;
        }
        this.showForm = false;
        this.showSuccess = true;
        this.fullUrl = `${window.location.origin}/${this.slug}#k=${shortSecret}`;
        QRCode.toCanvas(this.$refs.qrcode, this.fullUrl, { width: 128 });
        this.$refs.fullUrlInput.select();
      } catch {
        this.errorMessage = "An error occurred. Please try again.";
      } finally {
        this.loading = false;
      }
    },
    // ------------------------------
    // Short secret generator
    // ------------------------------
    /**
     * Generate a random alphanumeric secret of given length.
     * This is shared only in the URL fragment (#k=...), never sent to the server.
     */
    generateShortSecret(length) {
      const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
      let result = "";
      const array = new Uint8Array(length);
      window.crypto.getRandomValues(array);
      for (let i = 0; i < length; i++) {
        result += chars[array[i] % chars.length];
      }
      return result;
    },
    // ------------------------------
    // AES key derivation (PBKDF2)
    // ------------------------------
    /**
     * Derive a 256-bit AES-CBC key using PBKDF2-HMAC-SHA256.
     * @param {string} secret - User’s short secret (from URL hash fragment).
     * @param {string} ivHex - Initialization vector (hex), used as salt for PBKDF2.
     * @returns {Promise<CryptoKey>}
     */
    async deriveKey(secret, ivHex) {
      const enc = new TextEncoder();
      const keyMaterial = await crypto.subtle.importKey(
        "raw",
        enc.encode(secret),
        { name: "PBKDF2" },
        false,
        ["deriveKey"]
      );
      return crypto.subtle.deriveKey(
        {
          name: "PBKDF2",
          salt: enc.encode(ivHex),
          // Important: IV doubles as salt
          iterations: 1e5,
          hash: "SHA-256"
        },
        keyMaterial,
        { name: "AES-CBC", length: 256 },
        false,
        ["encrypt", "decrypt"]
      );
    },
    // ------------------------------
    // Encryption
    // ------------------------------
    /**
     * Encrypt plaintext using AES-CBC.
     * @param {string} plainText
     * @param {CryptoKey} key
     * @param {string} ivHex - IV in hex
     * @returns {Promise<string>} base64 ciphertext
     */
    async encryptPayload(plainText, key, ivHex) {
      const enc = new TextEncoder();
      const iv = this.hexToBytes(ivHex);
      const cipherBuffer = await crypto.subtle.encrypt(
        { name: "AES-CBC", iv },
        key,
        enc.encode(plainText)
      );
      return this.bytesToBase64(new Uint8Array(cipherBuffer));
    },
    // ------------------------------
    // Decryption
    // ------------------------------
    /**
     * Decrypt ciphertext using AES-CBC.
     * @param {string} base64Cipher - base64 encoded ciphertext
     * @param {CryptoKey} key
     * @param {string} ivHex - IV in hex
     * @returns {Promise<string>} plaintext string
     */
    async decryptPayload(base64Cipher, key, ivHex) {
      const cipherBytes = this.base64ToBytes(base64Cipher);
      const iv = this.hexToBytes(ivHex);
      const plainBuffer = await crypto.subtle.decrypt(
        { name: "AES-CBC", iv },
        key,
        cipherBytes
      );
      return new TextDecoder().decode(plainBuffer);
    },
    // ------------------------------
    // SHA-256 hashing (hex output)
    // ------------------------------
    /**
     * Compute SHA-256 of provided bytes.
     * @param {Uint8Array} bytes
     * @returns {Promise<string>} lowercase hex string
     */
    async sha256Hex(bytes) {
      const hashBuffer = await crypto.subtle.digest("SHA-256", bytes);
      return Array.from(new Uint8Array(hashBuffer)).map((b) => b.toString(16).padStart(2, "0")).join("");
    },
    // ------------------------------
    // Utility: Random bytes
    // ------------------------------
    /**
     * Generate cryptographically secure random bytes and return as hex string.
     * @param {number} length - number of bytes
     * @returns {Promise<string>}
     */
    async generateRandomBytes(length) {
      const array = new Uint8Array(length);
      window.crypto.getRandomValues(array);
      return Array.from(array).map((b) => ("00" + b.toString(16)).slice(-2)).join("");
    },
    // ------------------------------
    // Utility: Encoding helpers
    // ------------------------------
    /** Convert hex string to Uint8Array */
    hexToBytes(hex) {
      const bytes = new Uint8Array(hex.length / 2);
      for (let i = 0; i < bytes.length; i++) {
        bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
      }
      return bytes;
    },
    /** Convert base64 string → Uint8Array */
    base64ToBytes(base64) {
      const binary = atob(base64);
      const bytes = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
      }
      return bytes;
    },
    /** Convert Uint8Array → base64 string */
    bytesToBase64(bytes) {
      let binary = "";
      for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
      }
      return btoa(binary);
    },
    // ------------------------------
    // Clipboard helper
    // ------------------------------
    /**
     * Copy the generated Snipto URL to clipboard and show a toast.
     */
    copyUrl() {
      navigator.clipboard.writeText(this.fullUrl).then(() => {
        this.showToast = true;
        setTimeout(() => this.showToast = false, 2e3);
      });
    },
    // ------------------------------
    // CSRF token helper
    // ------------------------------
    /** Fetch CSRF token from <meta> tag */
    getCsrfToken() {
      return document.querySelector('meta[name="csrf-token"]').content;
    }
  };
}
window.sniptoComponent = sniptoComponent;
//# sourceMappingURL=snipto.js.map
