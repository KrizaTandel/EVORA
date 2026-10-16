/**
 * EVORA HRMS — Core Frontend JavaScript
 * College Web Development Project
 * Vanilla JS & Fetch API utilities
 */

const EVORA = {
  /**
   * Dynamically resolves the backend API base URL relative to document location
   */
  get apiBase() {
    const path = window.location.pathname;
    const frontendIdx = path.indexOf('/frontend');
    if (frontendIdx !== -1) {
      return path.substring(0, frontendIdx) + '/backend';
    }
    return '/backend';
  },

  /**
   * Universal Fetch API wrapper with JSON parsing and error handling
   */
  async request(endpoint, options = {}) {
    const url = `${this.apiBase}/${endpoint}`;
    const defaultHeaders = {
      'Accept': 'application/json',
      'Content-Type': 'application/json'
    };

    options.headers = {
      ...defaultHeaders,
      ...options.headers
    };

    try {
      const response = await fetch(url, options);
      const data = await response.json();
      return data;
    } catch (error) {
      console.error(`[EVORA API Error] Failed to fetch ${endpoint}:`, error);
      return {
        success: false,
        message: 'Network error or invalid server response.'
      };
    }
  },

  /**
   * Display accessible alerts or toast feedback
   */
  notify(message, type = 'info') {
    console.log(`[EVORA Notification] [${type.toUpperCase()}] ${message}`);
  }
};

window.EVORA = EVORA;
