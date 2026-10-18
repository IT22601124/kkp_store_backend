const fs = require('fs');

// 1. Update sales_provider.dart
{
  const filePath = 'F:/kkp_rep_mobile_app/lib/provider/sales_provider.dart';
  let content = fs.readFileSync(filePath, 'utf8');

  if (!content.includes('bankSlip')) {
    content = content.replace(
      /int\?\s*bankAccountId,\s*String\?\s*referenceNumber,\s*String\s*status/m,
      'int? bankAccountId,\n    String? referenceNumber,\n    String? bankSlip,\n    String status'
    );

    content = content.replace(
      /'reference_number':\s*referenceNumber,\s*'status':\s*status/m,
      "'reference_number': referenceNumber,\n        if (bankSlip != null && bankSlip.isNotEmpty) 'bank_slip': bankSlip,\n        'status': status"
    );

    fs.writeFileSync(filePath, content, 'utf8');
    console.log('sales_provider.dart updated successfully!');
  } else {
    console.log('sales_provider.dart already has bankSlip.');
  }
}
