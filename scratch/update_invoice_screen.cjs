const fs = require('fs');

const filePath = 'F:/kkp_rep_mobile_app/lib/screens/invoice_creation_screen.dart';
let raw = fs.readFileSync(filePath, 'utf8');

// Normalize line endings to work cleanly, but preserve original EOL style
const isCRLF = raw.includes('\r\n');
const lines = raw.split(/\r?\n/);

console.log('Total lines initially:', lines.length);

// 1. Add imports at top
let hasImportConvert = false;
let hasImportIO = false;
let hasImportPicker = false;

lines.forEach(l => {
  if (l.includes("dart:convert")) hasImportConvert = true;
  if (l.includes("dart:io")) hasImportIO = true;
  if (l.includes("image_picker/image_picker.dart")) hasImportPicker = true;
});

const importsToAdd = [];
if (!hasImportConvert) importsToAdd.push("import 'dart:convert';");
if (!hasImportIO) importsToAdd.push("import 'dart:io';");
if (!hasImportPicker) importsToAdd.push("import 'package:image_picker/image_picker.dart';");

if (importsToAdd.length > 0) {
  lines.splice(0, 0, ...importsToAdd);
  console.log('Added imports:', importsToAdd);
}

// Recombine to lines
let content = lines.join('\n');

// 2. Add state variables before showModalBottomSheet
const stateTarget = "final notesController = TextEditingController();\n    int? selectedBankAccountId;";
const stateReplace = `final notesController = TextEditingController();
    int? selectedBankAccountId;
    XFile? selectedSlipFile;
    String? selectedSlipBase64;`;

if (content.includes(stateTarget)) {
  content = content.replace(stateTarget, stateReplace);
  console.log('Added selectedSlipFile and selectedSlipBase64 state variables');
} else {
  console.error('Failed to find stateTarget!');
  process.exit(1);
}

// 3. Add pickSlipImage function inside StatefulBuilder
const builderTarget = `          builder: (context, setModalState) {
            final bankAccountProvider = Provider.of<BankAccountProvider>(context, listen: false);`;

const builderReplace = `          builder: (context, setModalState) {
            final bankAccountProvider = Provider.of<BankAccountProvider>(context, listen: false);

            Future<void> pickSlipImage(ImageSource source) async {
              try {
                final picker = ImagePicker();
                final picked = await picker.pickImage(
                  source: source,
                  maxWidth: 1600,
                  maxHeight: 1600,
                  imageQuality: 85,
                );
                if (picked != null) {
                  final bytes = await picked.readAsBytes();
                  final ext = picked.path.split('.').last.toLowerCase();
                  final mime = (ext == 'png') ? 'image/png' : 'image/jpeg';
                  final b64 = 'data:' + mime + ';base64,' + base64Encode(bytes);
                  setModalState(() {
                    selectedSlipFile = picked;
                    selectedSlipBase64 = b64;
                  });
                }
              } catch (e) {
                debugPrint('Error picking slip image: ' + e.toString());
              }
            }`;

if (content.includes(builderTarget)) {
  content = content.replace(builderTarget, builderReplace);
  console.log('Added pickSlipImage function inside StatefulBuilder');
} else {
  console.error('Failed to find builderTarget!');
  process.exit(1);
}

// 4. Add UI widget under bankRefController TextField
const uiTarget = `                              TextField(
                                controller: bankRefController,
                                style: const TextStyle(fontSize: 12),
                                decoration: InputDecoration(
                                  labelText: 'Bank Transfer Ref / Slip Number',
                                  hintText: 'e.g. TXN-998841 / Slip Ref',
                                  isDense: true,
                                  contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                                ),
                              ),`;

const uiReplace = `                              TextField(
                                controller: bankRefController,
                                style: const TextStyle(fontSize: 12),
                                decoration: InputDecoration(
                                  labelText: 'Bank Transfer Ref / Slip Number',
                                  hintText: 'e.g. TXN-998841 / Slip Ref',
                                  isDense: true,
                                  contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                                ),
                              ),
                              const SizedBox(height: 10),
                              Container(
                                padding: const EdgeInsets.all(10),
                                decoration: BoxDecoration(
                                  color: isDark ? AppColors.darkCard.withOpacity(0.5) : Colors.grey.shade50,
                                  borderRadius: BorderRadius.circular(8),
                                  border: Border.all(
                                    color: selectedSlipFile != null
                                        ? AppColors.emeraldSuccess.withOpacity(0.6)
                                        : (isDark ? AppColors.darkBorder : AppColors.lightBorder),
                                  ),
                                ),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Row(
                                          children: [
                                            Icon(
                                              Icons.receipt_long,
                                              size: 16,
                                              color: selectedSlipFile != null ? AppColors.emeraldSuccess : AppColors.primaryOrange,
                                            ),
                                            const SizedBox(width: 6),
                                            const Text(
                                              'Bank Clip / Slip Receipt Photo',
                                              style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                                            ),
                                          ],
                                        ),
                                        if (selectedSlipFile != null)
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                            decoration: BoxDecoration(
                                              color: AppColors.emeraldSuccess.withOpacity(0.15),
                                              borderRadius: BorderRadius.circular(4),
                                            ),
                                            child: const Row(
                                              mainAxisSize: MainAxisSize.min,
                                              children: [
                                                Icon(Icons.check_circle, size: 10, color: AppColors.emeraldSuccess),
                                                SizedBox(width: 3),
                                                Text(
                                                  'ATTACHED',
                                                  style: TextStyle(
                                                    fontSize: 9,
                                                    fontWeight: FontWeight.bold,
                                                    color: AppColors.emeraldSuccess,
                                                  ),
                                                ),
                                              ],
                                            ),
                                          ),
                                      ],
                                    ),
                                    const SizedBox(height: 8),
                                    if (selectedSlipFile != null) ...[
                                      Row(
                                        children: [
                                          ClipRRect(
                                            borderRadius: BorderRadius.circular(6),
                                            child: Image.file(
                                              File(selectedSlipFile!.path),
                                              width: 55,
                                              height: 55,
                                              fit: BoxFit.cover,
                                            ),
                                          ),
                                          const SizedBox(width: 10),
                                          Expanded(
                                            child: Column(
                                              crossAxisAlignment: CrossAxisAlignment.start,
                                              children: [
                                                Text(
                                                  selectedSlipFile!.name,
                                                  maxLines: 1,
                                                  overflow: TextOverflow.ellipsis,
                                                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600),
                                                ),
                                                const SizedBox(height: 2),
                                                const Text(
                                                  'Ready to record with sale',
                                                  style: TextStyle(fontSize: 10, color: Colors.grey),
                                                ),
                                                const SizedBox(height: 4),
                                                Row(
                                                  children: [
                                                    InkWell(
                                                      onTap: () => pickSlipImage(ImageSource.camera),
                                                      child: const Row(
                                                        children: [
                                                          Icon(Icons.camera_alt, size: 12, color: AppColors.primaryOrange),
                                                          SizedBox(width: 3),
                                                          Text('Retake', style: TextStyle(fontSize: 10, color: AppColors.primaryOrange, fontWeight: FontWeight.w600)),
                                                        ],
                                                      ),
                                                    ),
                                                    const SizedBox(width: 14),
                                                    InkWell(
                                                      onTap: () {
                                                        setModalState(() {
                                                          selectedSlipFile = null;
                                                          selectedSlipBase64 = null;
                                                        });
                                                      },
                                                      child: const Row(
                                                        children: [
                                                          Icon(Icons.delete_outline, size: 12, color: AppColors.roseDanger),
                                                          SizedBox(width: 3),
                                                          Text('Remove', style: TextStyle(fontSize: 10, color: AppColors.roseDanger, fontWeight: FontWeight.w600)),
                                                        ],
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                              ],
                                            ),
                                          ),
                                        ],
                                      ),
                                    ] else ...[
                                      Row(
                                        children: [
                                          Expanded(
                                            child: OutlinedButton.icon(
                                              onPressed: () => pickSlipImage(ImageSource.camera),
                                              icon: const Icon(Icons.camera_alt, size: 14, color: AppColors.primaryOrange),
                                              label: const Text('Take Photo', style: TextStyle(fontSize: 11, color: AppColors.primaryOrange)),
                                              style: OutlinedButton.styleFrom(
                                                side: const BorderSide(color: AppColors.primaryOrange),
                                                padding: const EdgeInsets.symmetric(vertical: 8),
                                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                                              ),
                                            ),
                                          ),
                                          const SizedBox(width: 8),
                                          Expanded(
                                            child: OutlinedButton.icon(
                                              onPressed: () => pickSlipImage(ImageSource.gallery),
                                              icon: Icon(Icons.photo_library, size: 14, color: isDark ? AppColors.darkText : AppColors.lightText),
                                              label: Text('Upload Slip', style: TextStyle(fontSize: 11, color: isDark ? AppColors.darkText : AppColors.lightText)),
                                              style: OutlinedButton.styleFrom(
                                                side: BorderSide(color: isDark ? AppColors.darkBorder : AppColors.lightBorder),
                                                padding: const EdgeInsets.symmetric(vertical: 8),
                                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                    ],
                                  ],
                                ),
                              ),`;

if (content.includes(uiTarget)) {
  content = content.replace(uiTarget, uiReplace);
  console.log('Added slip photo upload UI under bankRefController TextField');
} else {
  console.error('Failed to find uiTarget!');
  process.exit(1);
}

// 5. Pass bankSlip to salesProvider.createSale
const createSaleTarget = `                                        bankAccountId: paymentType == 'ONLINE' ? selectedBankAccountId : null,
                                        referenceNumber: paymentType == 'ONLINE' ? bankRefController.text : null,
                                        discount: discount,`;

const createSaleReplace = `                                        bankAccountId: paymentType == 'ONLINE' ? selectedBankAccountId : null,
                                        referenceNumber: paymentType == 'ONLINE' ? bankRefController.text : null,
                                        bankSlip: paymentType == 'ONLINE' ? selectedSlipBase64 : null,
                                        discount: discount,`;

if (content.includes(createSaleTarget)) {
  content = content.replace(createSaleTarget, createSaleReplace);
  console.log('Passed bankSlip to salesProvider.createSale');
} else {
  console.error('Failed to find createSaleTarget!');
  process.exit(1);
}

// Convert back to original EOL style
if (isCRLF) {
  content = content.replace(/\n/g, '\r\n');
}

fs.writeFileSync(filePath, content, 'utf8');
console.log('invoice_creation_screen.dart updated successfully!');
