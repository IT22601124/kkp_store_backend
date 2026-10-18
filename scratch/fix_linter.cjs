const fs = require('fs');
const filePath = 'F:/kkp_rep_mobile_app/lib/screens/invoice_creation_screen.dart';
let content = fs.readFileSync(filePath, 'utf8');
content = content.replace("'data:' + mime + ';base64,' + base64Encode(bytes)", "'data:$mime;base64,${base64Encode(bytes)}'");
content = content.replace("'Error picking slip image: ' + e.toString()", "'Error picking slip image: $e'");
fs.writeFileSync(filePath, content, 'utf8');
console.log('Fixed interpolation!');
