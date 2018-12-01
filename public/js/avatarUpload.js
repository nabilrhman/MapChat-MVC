var CLOUDINARY_URL = 'https://api.cloudinary.com/v1_1/nabilr/image/upload';
var CLOUDINARY_UPLOAD_PRESET = 'eu0zgzwd';

var imgPreview = document.getElementById('avatar');
var fileUplaod = document.getElementById('file-upload');

fileUpload.addEventListener('change', function (event) {
        var file = event.target.files[0];
        var formData = new FormData();
        formData.append('file', file);
        formData.append('upload_preset', CLOUDINARY_UPLOAD_PRESET);

        axios(
            {
                url: CLOUDINARY_URL,
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                data: formData
            }
        ).then(function (res) {
                console.log(res);
                imgPreview.src = res.data.secure_url;
            }
        ).catch(function (err) {
            console.error(err);
        })
    }
)
