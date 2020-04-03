<?php include __DIR__ . '/../config.php' ?>
<!DOCTYPE html>
<html>
<head>

    <meta charset="utf-8">
    <meta name="description" content="php webrtc">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1, maximum-scale=1">
    <meta itemprop="description" content="Video chat using the reference WebRTC application">
    <meta itemprop="name" content="WebRTC">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Swoole WebRTC</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <style>
        .videos {
            font-size: 0;
            height: 50%;
            float: left;
            width: 50%;
            padding: 10px;
        }

        .btn {
            margin: 20px;
            font-weight: normal;
            text-align: center;
            vertical-align: middle;
            cursor: pointer;
            background-image: none;
            white-space: nowrap;
            padding: 6px 12px;
            font-size: 13px;
            line-height: 1.428571429;
            border-radius: 2px;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            color: #fff;
            background-color: #3276b1;
            border-color: #2c699d;
        }
    </style>
</head>

<body>
<div style="display: block">
    <button class="btn" onclick="start()">连接</button>
    <button class="btn" onclick="leave()">离开</button>
</div>
<div>
    <div class="videos">
        <h1>Local</h1>
        <video id="localVideo" autoplay></video>
    </div>
    <div class="videos">
        <h1>Remote</h1>
        <video id="remoteVideo" autoplay></video>
    </div>
</div>

<script src="assets/js/adapter.js"></script>
<script type="text/javascript">
    const ws_config = '<?= $signaling_server ?>';
    const localVideo = document.getElementById('localVideo');
    const remoteVideo = document.getElementById('remoteVideo');
    const configuration = {
        iceServers: [{
            urls: '<?= $stun_server ?>'
        }]
    };

    let room_id = getQueryVariable('room_id');
    if (room_id == '' || room_id == null) {
        room_id = Math.random().toString(36).slice(-8);
        location.href = '?room_id=' + room_id;
    }
    let subject = 'room-' + room_id;//当前主题
    let answer = 0;
    let ws = null;
    let pc, localStream;

    function getMediaStream(stream) {
        localVideo.srcObject = localStream;
        localStream = stream;
    }

    function start() {
        ws = new WebSocket(ws_config);
        ws.onopen = function (e) {
            subscribe(subject);
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                console.error('the getUserMedia is not supported!');
                return;
            }
            navigator.mediaDevices.getUserMedia({
                audio: true,
                video: true
            }).then(function (stream) {
                if (localStream) {
                    stream.getAudioTracks().forEach((track) => {
                        localStream.addTrack(track);
                        stream.removeTrack(track);
                    });
                } else {
                    localStream = stream;
                }
                localVideo.srcObject = localStream;
                publish('call', null);
            }).catch(function (e) {
                console.error('Failed to get Media Stream!', e);
            });
        };
        ws.onmessage = function (e) {
            let package = JSON.parse(e.data);
            let data = package.data;
            console.log(e);
            switch (package.event) {
                case 'call':
                    icecandidate(localStream);
                    pc.createOffer({
                        offerToReceiveAudio: 1,
                        offerToReceiveVideo: 1
                    }).then(function (desc) {
                        pc.setLocalDescription(desc).then(
                            function () {
                                publish('offer', pc.localDescription);
                            }
                        ).catch(function (e) {
                            alert(e);
                        });
                    }).catch(function (e) {
                        alert(e);
                    });
                    break;
                case 'answer':
                    pc.setRemoteDescription(new RTCSessionDescription(data), function () {
                    }, function (e) {
                        alert(e);
                    });
                    break;
                case 'offer':
                    icecandidate(localStream);
                    pc.setRemoteDescription(new RTCSessionDescription(data), function () {
                        if (!answer) {
                            pc.createAnswer(function (desc) {
                                    pc.setLocalDescription(desc, function () {
                                        publish('answer', pc.localDescription);
                                    }, function (e) {
                                        alert(e);
                                    });
                                }
                                , function (e) {
                                    alert(e);
                                });
                            answer = 1;
                        }
                    }, function (e) {
                        alert(e);
                    });
                    break;
                case 'candidate':
                    pc.addIceCandidate(new RTCIceCandidate(data), function () {
                    }, function (e) {
                        alert(e);
                    });
                    break;
            }
        };
    }

    function leave() {
        pc.close();
    }

    function icecandidate(localStream) {
        pc = new RTCPeerConnection(configuration);
        pc.onicecandidate = function (event) {
            if (event.candidate) {
                publish('candidate', event.candidate);
            }
        };
        try {
            pc.addStream(localStream);
        } catch (e) {
            let tracks = localStream.getTracks();
            for (let i = 0; i < tracks.length; i++) {
                pc.addTrack(tracks[i], localStream);
            }
        }
        pc.onaddstream = function (e) {
            remoteVideo.srcObject = e.stream;
        };
    }

    function publish(event, data) {
        let obj = {
            cmd: 'publish',
            subject: subject,
            event: event,
            data: data
        };
        console.log(obj);
        ws.send(JSON.stringify(obj));
    }

    function subscribe(subject) {
        let obj = {
            cmd: 'subscribe',
            subject: subject
        };
        console.log(obj);
        ws.send(JSON.stringify(obj));
    }

    function getQueryVariable(variable) {
        var query = window.location.search.substring(1);
        var vars = query.split("&");
        for (var i = 0; i < vars.length; i++) {
            var pair = vars[i].split("=");
            if (pair[0] == variable) {
                return pair[1];
            }
        }
        return false;
    }
</script>
</body>
</html>
