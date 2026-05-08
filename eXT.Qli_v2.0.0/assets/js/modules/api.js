const BASE_PATH = window.EXTQLI_API_BASE_PATH || '/eXT.Qli';

export const endpoints = {
    agents: `${BASE_PATH}/backend/api/agents.php`,
    saveIdentity: `${BASE_PATH}/backend/api/save_agent_identity.php`,
    deleteAgent: `${BASE_PATH}/backend/api/delete_agent.php`,
    signaling: `${BASE_PATH}/backend/api/signaling.php`,
    taskResult: `${BASE_PATH}/backend/api/agent_task_result.php`,
    deprecatedTask: `${BASE_PATH}/backend/api/send_agent_task.php`
};

export async function getJson(url, options = {}) {
    const response = await fetch(url, {
        method: 'GET',
        headers: { Accept: 'application/json', ...(options.headers || {}) },
        ...options
    });
    return response.json();
}

export async function postJson(url, payload, options = {}) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(options.headers || {})
        },
        body: JSON.stringify(payload || {}),
        ...options
    });
    return response.json();
}
