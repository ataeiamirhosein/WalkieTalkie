document.addEventListener('DOMContentLoaded', function() {
    // DOM elements
    const myIdInput = document.getElementById('my-id');
    const partnerIdInput = document.getElementById('partner-id');
    const connectBtn = document.getElementById('connect-btn');
    const disconnectBtn = document.getElementById('disconnect-btn');
    const talkBtn = document.getElementById('talk-btn');
    const audioPlayer = document.getElementById('audio-player');
    const statusMessages = document.getElementById('status-messages');
    const talkiePanel = document.querySelector('.talkie-panel');
    
    // Variables
    let myId = '';
    let partnerId = '';
    let mediaRecorder;
    let audioChunks = [];
    let checkInterval;
    let connectionActive = false;
    let currentAudioUrl = null;
    let audioContext;
    let mediaStream;
    
    // Add status message
    function addStatusMessage(message, type = 'info') {
        const messageDiv = document.createElement('div');
        messageDiv.className = `notification ${type}`;
        messageDiv.textContent = message;
        statusMessages.appendChild(messageDiv);
        statusMessages.scrollTop = statusMessages.scrollHeight;
    }
    
    // Initialize audio recording
    async function initRecording() {
        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            mediaRecorder = new MediaRecorder(mediaStream, {
                mimeType: 'audio/webm;codecs=opus'
            });
            
            mediaRecorder.ondataavailable = event => {
                audioChunks.push(event.data);
            };
            
            mediaRecorder.onstop = async () => {
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                audioChunks = [];
                await sendAudio(audioBlob);
            };
            
            addStatusMessage('Microphone ready', 'success');
        } catch (error) {
            addStatusMessage('Error accessing microphone: ' + error.message, 'error');
        }
    }
    
    // Send audio to server
    async function sendAudio(audioBlob) {
        const formData = new FormData();
        formData.append('my_id', myId);
        formData.append('partner_id', partnerId);
        formData.append('audio', audioBlob, 'recording.webm');
        
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000);
            
            const response = await fetch('api.php?action=upload', {
                method: 'POST',
                body: formData,
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const responseText = await response.text();
            const result = safeJsonParse(responseText);
            
            if (!result) {
                throw new Error('Invalid server response');
            }
            
            if (result.success) {
                addStatusMessage('Voice message sent', 'success');
            } else {
                throw new Error(result.error || 'Failed to send voice message');
            }
        } catch (error) {
            addStatusMessage('Error sending voice message: ' + error.message, 'error');
        }
    }
    
    // Safe JSON parsing
    function safeJsonParse(str) {
        try {
            return JSON.parse(str);
        } catch (e) {
            console.error('JSON parse error:', e, 'Response:', str);
            return null;
        }
    }
    
    // Check for new messages from partner
    async function checkForMessages() {
        if (!connectionActive) return;
        
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000);
            
            const response = await fetch(`api.php?action=check&my_id=${myId}&partner_id=${partnerId}&_=${Date.now()}`, {
                signal: controller.signal,
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                }
            });
            
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }
            
            const responseText = await response.text();
            const result = safeJsonParse(responseText);
            
            if (!result) {
                throw new Error('Invalid server response');
            }
            
            if (result.success && result.data?.audio) {
                await playAudioMessage(result.data.audio);
            }
            
            if (result.data?.connection_active === false) {
                addStatusMessage('Connection lost (inactive for 30 seconds)', 'error');
                await disconnect();
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                addStatusMessage('Request timeout checking messages', 'error');
            } else {
                addStatusMessage('Error checking messages: ' + error.message, 'error');
            }
        }
    }
    
    // Play received audio message
    async function playAudioMessage(audioPath) {
        try {
            // Clean up previous audio if exists
            if (currentAudioUrl) {
                URL.revokeObjectURL(currentAudioUrl);
                currentAudioUrl = null;
            }
            
            // Fetch audio file with timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000);
            
            const audioResponse = await fetch(audioPath, { 
                cache: 'no-store',
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            if (!audioResponse.ok) throw new Error('Audio file not found');
            
            const audioBlob = await audioResponse.blob();
            currentAudioUrl = URL.createObjectURL(audioBlob);
            
            // Play audio
            audioPlayer.src = currentAudioUrl;
            
            // Wait for audio to be ready
            await new Promise((resolve, reject) => {
                audioPlayer.oncanplaythrough = resolve;
                audioPlayer.onerror = () => reject(new Error('Audio playback failed'));
                audioPlayer.load();
            });
            
            await audioPlayer.play();
            addStatusMessage('Playing received message', 'success');
            
            // Delete the audio file after playback starts
            try {
                await fetch(`api.php?action=delete&file=${encodeURIComponent(audioPath)}`);
            } catch (e) {
                console.error('Failed to delete audio:', e);
            }
            
        } catch (error) {
            addStatusMessage('Error playing message: ' + error.message, 'error');
        }
    }
    
    // Connect users
    async function connect() {
        myId = myIdInput.value.trim();
        partnerId = partnerIdInput.value.trim();
        
        if (!myId || !partnerId) {
            addStatusMessage('Please enter both your ID and partner ID', 'error');
            return;
        }
        
        try {
            // Clear any previous connection
            if (connectionActive) {
                await disconnect();
            }
            
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000);
            
            const response = await fetch(`api.php?action=connect&my_id=${myId}&partner_id=${partnerId}&_=${Date.now()}`, {
                signal: controller.signal,
                headers: {
                    'Accept': 'application/json',
                    'Cache-Control': 'no-cache'
                }
            });
            
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`Server returned ${response.status}: ${errorText}`);
            }
            
            const result = await response.json();
            
            if (!result?.success) {
                throw new Error(result?.error || 'Connection failed');
            }
            
            // Connection successful
            addStatusMessage(`Connected to partner ${partnerId}`, 'success');
            connectionActive = true;
            
            // Show talkie panel
            talkiePanel.style.display = 'block';
            
            // Disable connect button, enable disconnect
            connectBtn.disabled = true;
            disconnectBtn.disabled = false;
            
            // Initialize recording
            await initRecording();
            
            // Start checking for messages every 2 seconds
            checkInterval = setInterval(checkForMessages, 2000);
            
        } catch (error) {
            let errorMsg = 'Connection error: ';
            
            if (error.name === 'AbortError') {
                errorMsg += 'Request timed out';
            } else if (error.message.includes('Internal server error')) {
                errorMsg += 'Server encountered an error. Please try again.';
            } else {
                errorMsg += error.message;
            }
            
            addStatusMessage(errorMsg, 'error');
            console.error('Connection error details:', error);
            
            // Clean up if connection was partially established
            if (mediaStream) {
                mediaStream.getTracks().forEach(track => track.stop());
            }
            if (audioContext) {
                await audioContext.close();
            }
        }
    }
    
    // Disconnect users
    async function disconnect() {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);
            
            const response = await fetch(`api.php?action=disconnect&my_id=${myId}`, {
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            const result = await response.json();
            
            if (!result?.success) {
                throw new Error(result?.error || 'Disconnect failed');
            }
            
            addStatusMessage('Disconnected', 'success');
            
        } catch (error) {
            addStatusMessage('Disconnect error: ' + error.message, 'error');
        } finally {
            // Clean up resources
            connectionActive = false;
            clearInterval(checkInterval);
            
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            
            if (mediaStream) {
                mediaStream.getTracks().forEach(track => track.stop());
            }
            
            if (audioContext) {
                await audioContext.close();
            }
            
            if (currentAudioUrl) {
                URL.revokeObjectURL(currentAudioUrl);
                currentAudioUrl = null;
            }
            
            // Hide talkie panel
            talkiePanel.style.display = 'none';
            
            // Reset buttons
            connectBtn.disabled = false;
            disconnectBtn.disabled = true;
            
            // Clear audio
            audioPlayer.src = '';
        }
    }
    
    // Event listeners
    connectBtn.addEventListener('click', connect);
    disconnectBtn.addEventListener('click', disconnect);
    
    // Talk button events
    talkBtn.addEventListener('mousedown', startRecording);
    talkBtn.addEventListener('touchstart', startRecording);
    talkBtn.addEventListener('mouseup', stopRecording);
    talkBtn.addEventListener('touchend', stopRecording);
    talkBtn.addEventListener('mouseleave', stopRecording);
    
    function startRecording(e) {
        e.preventDefault();
        if (!connectionActive) return;
        
        if (mediaRecorder && mediaRecorder.state === 'inactive') {
            audioChunks = []; // Clear previous chunks
            mediaRecorder.start(100); // Collect data every 100ms
            talkBtn.textContent = 'Recording... Release to send';
            addStatusMessage('Recording started', 'info');
        }
    }
    
    function stopRecording(e) {
        e.preventDefault();
        if (!connectionActive) return;
        
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            talkBtn.textContent = 'Hold to Talk';
            addStatusMessage('Recording stopped', 'info');
        }
    }
    
    // Prevent default for touch events
    talkBtn.addEventListener('touchstart', function(e) {
        e.preventDefault();
    });
    
    talkBtn.addEventListener('touchend', function(e) {
        e.preventDefault();
    });
    
    // Clean up on page unload
    window.addEventListener('beforeunload', async function() {
        if (connectionActive) {
            await disconnect();
        }
    });
});